import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import {
  Calendar,
  CalendarDays,
  Clock,
  Users,
  Shield,
  UserCheck,
  Plus,
  Search,
  Filter,
  RefreshCw,
  ChevronLeft,
  ChevronRight,
  Edit2,
  Trash2,
  X,
  AlertTriangle,
  CheckCircle2,
  MapPin,
  FileText,
  AlertCircle,
  LayoutGrid,
  Sun,
  Sunset,
  Moon,
  Briefcase,
  XCircle,
  Check,
  ChevronDown,
  Info,
  Sparkles,
  ArrowRight,
  CheckCheck,
} from 'lucide-react';
import {
  staffScheduleApi,
  StaffScheduleItem,
  ShiftType,
  StaffProfileItem,
  ScheduleSummary,
} from '../Services/staffScheduleApi';
import { useRealtimeSync } from '../Hooks/useRealtimeSync';
import { CustomSelect, SelectOption } from './Common/CustomSelect';

interface ManagementStaffSchedulePanelProps {
  embedded?: boolean;
}

export const ManagementStaffSchedulePanel: React.FC<ManagementStaffSchedulePanelProps> = ({
  embedded = false,
}) => {
  // ================= STATE =================
  const [currentDate, setCurrentDate] = useState<Date>(new Date());
  const [schedules, setSchedules] = useState<StaffScheduleItem[]>([]);
  const [shiftTypes, setShiftTypes] = useState<ShiftType[]>([]);
  const [staffCandidates, setStaffCandidates] = useState<StaffProfileItem[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Filters
  const [roleFilter, setRoleFilter] = useState<string>('ALL'); // 'ALL' | 'RECEPTIONIST' | 'SECURITY_GUARD'
  const [staffFilter, setStaffFilter] = useState<string>('ALL');
  const [shiftFilter, setShiftFilter] = useState<string>('ALL');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Summary / KPIs
  const [summary, setSummary] = useState<ScheduleSummary>({
    total_shifts: 0,
    reception_shifts: 0,
    security_shifts: 0,
    scheduled_count: 0,
    completed_count: 0,
    cancelled_count: 0,
    start_date: '',
    end_date: '',
  });

  // Modal Create / Edit
  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);
  const [editingSchedule, setEditingSchedule] = useState<StaffScheduleItem | null>(null);
  const [modalStaffId, setModalStaffId] = useState<string>('');
  const [modalShiftId, setModalShiftId] = useState<string>('');
  const [modalDate, setModalDate] = useState<string>('');
  const [modalLocation, setModalLocation] = useState<string>('');
  const [modalNotes, setModalNotes] = useState<string>('');
  const [modalStatus, setModalStatus] = useState<string>('SCHEDULED');
  const [modalError, setModalError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);

  // Modal Delete / Cancel Confirmation
  const [deleteConfirmSchedule, setDeleteConfirmSchedule] = useState<StaffScheduleItem | null>(null);
  const [isDeleting, setIsDeleting] = useState<'delete' | 'cancel' | null>(null);

  // Month View Day Detail Modal (khi click vào 1 ngày trên lịch tháng)
  const [selectedDayDetail, setSelectedDayDetail] = useState<string | null>(null);
  const [isUpdatingStatus, setIsUpdatingStatus] = useState<string | null>(null);

  // Realtime Notification Toast
  const [realtimeToast, setRealtimeToast] = useState<{ message: string; timestamp: number } | null>(null);

  // ================= DATE HELPERS =================
  const formatDateString = (d: Date): string => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const parseScheduleDateKey = (workDateStr: string): string => {
    if (!workDateStr) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(workDateStr)) {
      return workDateStr;
    }
    if (workDateStr.includes('T')) {
      const d = new Date(workDateStr);
      if (!isNaN(d.getTime())) {
        return formatDateString(d);
      }
    }
    return workDateStr.slice(0, 10);
  };

  const formatDisplayDate = (dStr: string): string => {
    if (!dStr) return '';
    const clean = parseScheduleDateKey(dStr);
    const parts = clean.split('-');
    if (parts.length === 3) {
      return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }
    return dStr;
  };

  const getMonthRange = useCallback((date: Date): { start: Date; end: Date; weeks: Date[][] } => {
    const year = date.getFullYear();
    const month = date.getMonth();

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    // Tính ngày Thứ 2 đầu tiên của tuần chứa ngày 1
    const firstDayOfWeek = firstDay.getDay();
    const diffToFirstMonday = firstDay.getDate() - (firstDayOfWeek === 0 ? 6 : firstDayOfWeek - 1);
    const calendarStart = new Date(year, month, diffToFirstMonday);

    // Tính ngày Chủ nhật cuối cùng của tuần chứa ngày cuối tháng
    const lastDayOfWeek = lastDay.getDay();
    const diffToLastSunday = lastDayOfWeek === 0 ? 0 : 7 - lastDayOfWeek;
    const calendarEnd = new Date(year, month + 1, 0 + diffToLastSunday);

    const weeks: Date[][] = [];
    const curr = new Date(calendarStart);

    while (curr <= calendarEnd) {
      const week: Date[] = [];
      for (let i = 0; i < 7; i++) {
        week.push(new Date(curr));
        curr.setDate(curr.getDate() + 1);
      }
      weeks.push(week);
    }

    return { start: calendarStart, end: calendarEnd, weeks };
  }, []);

  // Phạm vi ngày hiện tại theo lịch tháng
  const activeDateRange = useMemo(() => {
    const { start, end, weeks } = getMonthRange(currentDate);
    return {
      startDate: formatDateString(start),
      endDate: formatDateString(end),
      weeks,
    };
  }, [currentDate, getMonthRange]);

  // ================= LOAD DATA =================
  const fetchSchedules = useCallback(async (isSilent = false) => {
    if (!isSilent) setIsLoading(true);
    else setIsRefreshing(true);
    setErrorMsg(null);

    try {
      const res = await staffScheduleApi.getSchedules({
        start_date: activeDateRange.startDate,
        end_date: activeDateRange.endDate,
        role: roleFilter,
        staff_profile_id: staffFilter,
        shift_type_id: shiftFilter,
        status: statusFilter,
        search: searchQuery,
      });

      setSchedules(res.data || []);
      if (res.summary) {
        setSummary(res.summary);
      }
    } catch (err: any) {
      setErrorMsg(err.message || 'Không thể tải lịch trực');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  }, [activeDateRange, roleFilter, staffFilter, shiftFilter, statusFilter, searchQuery]);

  // Tải danh mục loại ca và danh sách nhân viên 1 lần khi mount
  useEffect(() => {
    const loadMetadata = async () => {
      try {
        const [shifts, staff] = await Promise.all([
          staffScheduleApi.getShiftTypes(),
          staffScheduleApi.getStaffCandidates(),
        ]);
        setShiftTypes(shifts);
        setStaffCandidates(staff);
      } catch (err: any) {
        // ignore or log
      }
    };
    loadMetadata();
  }, []);

  // Tải lịch khi phạm vi ngày hoặc bộ lọc thay đổi
  useEffect(() => {
    fetchSchedules();
  }, [fetchSchedules]);

  // ================= REALTIME SYNC LISTENER =================
  useRealtimeSync({
    channel: 'quoc-tin.schedules',
    onEvent: (payload) => {
      // Chỉ xử lý các mutation liên quan đến module schedules
      if (payload.module !== 'schedules' && payload.module !== 'staff_schedules') {
        return;
      }

      const action = payload.action?.toUpperCase();
      const entityId = payload.entity_id || payload.id;
      const extra = payload.schedule || payload;

      // 1. Thông báo toast cập nhật realtime
      setRealtimeToast({
        message: `Đã đồng bộ lịch trực (${action === 'CREATED' ? 'Thêm mới' : action === 'UPDATED' ? 'Cập nhật' : 'Xóa/Hủy ca'})`,
        timestamp: Date.now(),
      });

      // 2. Cập nhật state cục bộ ngay lập tức không cần F5/reload
      if (action === 'CREATED' && extra && extra.id) {
        // Kiểm tra xem ca mới có nằm trong khoảng ngày đang xem không
        const itemDate = extra.work_date ? parseScheduleDateKey(extra.work_date) : '';
        if (itemDate >= activeDateRange.startDate && itemDate <= activeDateRange.endDate) {
          setSchedules((prev) => {
            if (prev.some((s) => s.id === extra.id)) return prev;
            return [...prev, extra].sort((a, b) => a.work_date.localeCompare(b.work_date));
          });
        }
      } else if (action === 'UPDATED' && extra && extra.id) {
        setSchedules((prev) =>
          prev.map((s) => (s.id === extra.id ? { ...s, ...extra } : s))
        );
      } else if (action === 'DELETED') {
        setSchedules((prev) => prev.filter((s) => s.id !== entityId));
      } else if (action === 'CANCELLED') {
        setSchedules((prev) =>
          prev.map((s) => (s.id === entityId ? { ...s, status: 'CANCELLED' } : s))
        );
      } else {
        // Silent refetch nếu payload chưa đủ dữ liệu chi tiết
        fetchSchedules(true);
      }
    },
    onReconnect: () => {
      // Tự động đồng bộ lại khi kết nối lại
      fetchSchedules(true);
    },
  });

  // Auto-hide Realtime Toast sau 4 giây
  useEffect(() => {
    if (realtimeToast) {
      const timer = setTimeout(() => {
        setRealtimeToast(null);
      }, 4000);
      return () => clearTimeout(timer);
    }
  }, [realtimeToast]);

  // ================= NAVIGATION HANDLERS =================
  const handlePrev = () => {
    setCurrentDate((prev) => {
      const next = new Date(prev);
      next.setMonth(next.getMonth() - 1);
      return next;
    });
  };

  const handleNext = () => {
    setCurrentDate((prev) => {
      const next = new Date(prev);
      next.setMonth(next.getMonth() + 1);
      return next;
    });
  };

  const handleToday = () => {
    setCurrentDate(new Date());
  };

  // Group schedules by work_date string: { '2026-10-20': [schedule1, schedule2] }
  const schedulesByDate = useMemo(() => {
    const map = new Map<string, StaffScheduleItem[]>();
    for (const s of schedules) {
      const dateKey = parseScheduleDateKey(s.work_date);
      if (!map.has(dateKey)) {
        map.set(dateKey, []);
      }
      map.get(dateKey)!.push(s);
    }
    return map;
  }, [schedules]);

  // Kiểm tra trước nếu nhân viên đã có ca trực trong ngày được chọn trong Modal
  const existingStaffShiftOnModalDate = useMemo(() => {
    if (!modalStaffId || !modalDate) return null;
    const daySchedules = schedulesByDate.get(modalDate) || [];
    return daySchedules.find(
      (s) =>
        s.staff_profile_id === modalStaffId &&
        s.status !== 'CANCELLED' &&
        s.id !== editingSchedule?.id
    );
  }, [modalStaffId, modalDate, schedulesByDate, editingSchedule]);

  // ================= MODAL HANDLERS =================
  const handleOpenCreateModal = (defaultDate?: string, defaultShiftId?: string) => {
    setEditingSchedule(null);
    setModalStaffId(staffCandidates[0]?.id || '');
    setModalShiftId(defaultShiftId || shiftTypes[0]?.id || '');
    setModalDate(defaultDate || formatDateString(new Date()));
    setModalLocation('');
    setModalNotes('');
    setModalStatus('SCHEDULED');
    setModalError(null);
    setIsModalOpen(true);
  };

  const handleOpenEditModal = (schedule: StaffScheduleItem) => {
    setEditingSchedule(schedule);
    setModalStaffId(schedule.staff_profile_id);
    setModalShiftId(schedule.shift_type_id);
    setModalDate(parseScheduleDateKey(schedule.work_date));
    setModalLocation(schedule.assigned_location || '');
    setModalNotes(schedule.notes || '');
    setModalStatus(schedule.status || 'SCHEDULED');
    setModalError(null);
    setIsModalOpen(true);
  };

  const handleSaveSchedule = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!modalStaffId || !modalShiftId || !modalDate) {
      setModalError('Vui lòng chọn đầy đủ nhân viên, ca trực và ngày làm việc.');
      return;
    }

    if (!editingSchedule && existingStaffShiftOnModalDate) {
      setModalError(
        `Nhân viên này đã được xếp ca "${existingStaffShiftOnModalDate.shift_type?.shift_name || 'ca trực'}" trong ngày ${modalDate}. Theo quy định, mỗi nhân viên chỉ trực 1 ca/ngày.`
      );
      return;
    }

    setIsSubmitting(true);
    setModalError(null);

    try {
      if (editingSchedule) {
        await staffScheduleApi.updateSchedule(editingSchedule.id, {
          staff_profile_id: modalStaffId,
          shift_type_id: modalShiftId,
          work_date: modalDate,
          assigned_location: modalLocation,
          notes: modalNotes,
          status: modalStatus,
        });
      } else {
        await staffScheduleApi.createSchedule({
          staff_profile_id: modalStaffId,
          shift_type_id: modalShiftId,
          work_date: modalDate,
          assigned_location: modalLocation,
          notes: modalNotes,
          status: modalStatus,
        });
      }

      setIsModalOpen(false);
      fetchSchedules(true);
    } catch (err: any) {
      setModalError(err.message || 'Đã xảy ra lỗi khi lưu ca trực.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleConfirmDeleteOrCancel = async (mode: 'delete' | 'cancel') => {
    if (!deleteConfirmSchedule) return;
    const targetSchedule = deleteConfirmSchedule;
    const targetId = targetSchedule.id;

    setIsDeleting(mode);

    // 1. Cập nhật giao diện tức thì (Optimistic UI - 0ms delay)
    if (mode === 'cancel') {
      setSchedules((prev) =>
        prev.map((s) => (s.id === targetId ? { ...s, status: 'CANCELLED' } : s))
      );
      setRealtimeToast({
        message: 'Đã hủy ca trực thành công!',
        timestamp: Date.now(),
      });
    } else {
      setSchedules((prev) => prev.filter((s) => s.id !== targetId));
      setRealtimeToast({
        message: 'Đã xóa hoàn toàn ca trực!',
        timestamp: Date.now(),
      });
    }

    try {
      await staffScheduleApi.deleteOrCancelSchedule(targetId, mode);
      setDeleteConfirmSchedule(null);
      fetchSchedules(true);
    } catch (err: any) {
      setDeleteConfirmSchedule(null);
      if (err.status === 404 || err.message?.includes('không tồn tại') || err.message?.includes('No query results')) {
        setRealtimeToast({
          message: 'Ca trực đã được xóa hoặc không còn tồn tại trên hệ thống.',
          timestamp: Date.now(),
        });
      } else {
        setRealtimeToast({
          message: err.message || 'Không thể xóa hoặc hủy ca trực.',
          timestamp: Date.now(),
        });
      }
      fetchSchedules(true);
    } finally {
      setIsDeleting(null);
    }
  };

  const handleQuickUpdateStatus = async (
    scheduleId: string,
    newStatus: 'SCHEDULED' | 'COMPLETED' | 'CANCELLED'
  ) => {
    setIsUpdatingStatus(scheduleId);

    // Optimistic UI update: Cập nhật ngay trên màn hình không phải chờ
    setSchedules((prev) =>
      prev.map((s) => (s.id === scheduleId ? { ...s, status: newStatus } : s))
    );

    const msg =
      newStatus === 'COMPLETED'
        ? 'Đã xác nhận hoàn thành ca trực thành công!'
        : newStatus === 'CANCELLED'
        ? 'Đã chuyển ca trực sang trạng thái Đã hủy.'
        : 'Đã khôi phục ca trực về trạng thái Đã lên lịch.';

    setRealtimeToast({ message: msg, timestamp: Date.now() });
    setTimeout(() => setRealtimeToast(null), 3000);

    try {
      await staffScheduleApi.updateSchedule(scheduleId, { status: newStatus });
      fetchSchedules(true);
    } catch (err: any) {
      if (err.status === 404 || err.message?.includes('không tồn tại') || err.message?.includes('No query results')) {
        setRealtimeToast({
          message: 'Ca trực này không tồn tại hoặc đã được xóa trước đó.',
          timestamp: Date.now(),
        });
      } else {
        setRealtimeToast({
          message: err.message || 'Không thể cập nhật trạng thái ca trực.',
          timestamp: Date.now(),
        });
      }
      fetchSchedules(true);
    } finally {
      setIsUpdatingStatus(null);
    }
  };

  const handleBatchDayStatus = async (
    dateKey: string,
    newStatus: 'COMPLETED' | 'CANCELLED'
  ) => {
    const dayItems = (schedulesByDate.get(dateKey) || []).filter(
      (s) => s.status === 'SCHEDULED'
    );
    if (!dayItems.length) return;

    const actionText = newStatus === 'COMPLETED' ? 'HOÀN THÀNH' : 'HỦY BỎ';
    if (!window.confirm(`Xác nhận chuyển ${dayItems.length} ca chờ trực trong ngày sang trạng thái ${actionText}?`)) {
      return;
    }

    setIsUpdatingStatus('batch');

    // Optimistic UI update cho cả ngày
    const dayItemIds = new Set(dayItems.map((item) => item.id));
    setSchedules((prev) =>
      prev.map((s) => (dayItemIds.has(s.id) ? { ...s, status: newStatus } : s))
    );

    setRealtimeToast({
      message: `Đã ${newStatus === 'COMPLETED' ? 'hoàn thành' : 'hủy'} ${dayItems.length} ca trực thành công!`,
      timestamp: Date.now(),
    });
    setTimeout(() => setRealtimeToast(null), 3000);

    try {
      await Promise.all(
        dayItems.map((item) =>
          staffScheduleApi.updateSchedule(item.id, { status: newStatus })
        )
      );
      fetchSchedules(true);
    } catch (err: any) {
      alert(err.message || `Lỗi khi xử lý hàng loạt: ${err}`);
      fetchSchedules(true);
    } finally {
      setIsUpdatingStatus(null);
    }
  };

  // ================= VIEW HELPERS =================
  const getShiftIcon = (code: string) => {
    switch (code) {
      case 'CA_SANG':
        return <Sun className="w-4 h-4 text-amber-500" />;
      case 'CA_CHIEU':
        return <Sunset className="w-4 h-4 text-orange-500" />;
      case 'CA_DEM':
        return <Moon className="w-4 h-4 text-indigo-400" />;
      default:
        return <Briefcase className="w-4 h-4 text-slate-500" />;
    }
  };

  const getRoleBadge = (roles?: Array<{ role_code: string }>) => {
    const codes = roles?.map((r) => r.role_code) || [];
    if (codes.includes('RECEPTIONIST')) {
      return (
        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
          <UserCheck className="w-3 h-3 text-sky-600" /> Lễ tân
        </span>
      );
    }
    if (codes.includes('SECURITY_GUARD')) {
      return (
        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
          <Shield className="w-3 h-3 text-emerald-600" /> Bảo vệ
        </span>
      );
    }
    return (
      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-50 text-slate-600 border border-slate-200">
        Nhân sự
      </span>
    );
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'SCHEDULED':
        return (
          <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
            Đã lên lịch
          </span>
        );
      case 'COMPLETED':
        return (
          <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            Hoàn thành
          </span>
        );
      case 'CANCELLED':
        return (
          <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
            Đã hủy
          </span>
        );
      default:
        return (
          <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700">
            {status}
          </span>
        );
    }
  };

  // ================= COMBOBOX OPTIONS (CHUẨN STYLE CUSTOM SELECT) =================
  const roleFilterOptions: SelectOption[] = useMemo(
    () => [
      { value: 'ALL', label: 'Mọi vị trí (Lễ tân & Bảo vệ)', icon: <Users className="w-3.5 h-3.5" /> },
      { value: 'RECEPTIONIST', label: 'Bộ phận Lễ tân', icon: <UserCheck className="w-3.5 h-3.5 text-sky-600" /> },
      { value: 'SECURITY_GUARD', label: 'Bộ phận An ninh / Bảo vệ', icon: <Shield className="w-3.5 h-3.5 text-emerald-600" /> },
    ],
    []
  );

  const staffFilterOptions: SelectOption[] = useMemo(
    () => [
      { value: 'ALL', label: 'Tất cả nhân viên', icon: <Users className="w-3.5 h-3.5" /> },
      ...staffCandidates.map((c) => {
        const isRec = c.user?.roles?.some((r) => r.role_code === 'RECEPTIONIST');
        return {
          value: c.id,
          label: `${c.user?.full_name || c.employee_code} (${c.employee_code})`,
          description: c.job_title,
          icon: isRec ? <UserCheck className="w-3.5 h-3.5 text-sky-600" /> : <Shield className="w-3.5 h-3.5 text-emerald-600" />,
          badge: isRec ? 'Lễ tân' : 'Bảo vệ',
          badgeColor: isRec ? 'bg-sky-50 text-sky-700 border border-sky-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200',
        };
      }),
    ],
    [staffCandidates]
  );

  const shiftFilterOptions: SelectOption[] = useMemo(
    () => [
      { value: 'ALL', label: 'Tất cả loại ca', icon: <Clock className="w-3.5 h-3.5" /> },
      ...shiftTypes.map((st) => ({
        value: st.id,
        label: st.shift_name,
        description: `${st.start_time.slice(0, 5)} - ${st.end_time.slice(0, 5)}`,
        icon: getShiftIcon(st.shift_code),
        badge: st.is_overnight ? 'Qua đêm' : undefined,
      })),
    ],
    [shiftTypes]
  );

  const statusFilterOptions: SelectOption[] = useMemo(
    () => [
      { value: 'ALL', label: 'Mọi trạng thái', icon: <Filter className="w-3.5 h-3.5" /> },
      { value: 'SCHEDULED', label: 'Đã lên lịch', icon: <Clock className="w-3.5 h-3.5 text-blue-500" />, badge: 'Chờ trực', badgeColor: 'bg-blue-50 text-blue-700' },
      { value: 'COMPLETED', label: 'Đã hoàn thành', icon: <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />, badge: 'Hoàn thành', badgeColor: 'bg-emerald-50 text-emerald-700' },
      { value: 'CANCELLED', label: 'Đã hủy', icon: <XCircle className="w-3.5 h-3.5 text-rose-500" />, badge: 'Đã hủy', badgeColor: 'bg-rose-50 text-rose-700' },
    ],
    []
  );

  const modalStaffOptions: SelectOption[] = useMemo(
    () =>
      staffCandidates.map((c) => {
        const isRec = c.user?.roles?.some((r) => r.role_code === 'RECEPTIONIST');
        const roles = c.user?.roles?.map((r) => r.role_name).join(', ') || '';
        return {
          value: c.id,
          label: `${c.user?.full_name} (${c.employee_code})`,
          description: `${c.job_title} • [${roles}]`,
          icon: isRec ? <UserCheck className="w-3.5 h-3.5 text-sky-600" /> : <Shield className="w-3.5 h-3.5 text-emerald-600" />,
          badge: isRec ? 'Lễ tân' : 'Bảo vệ',
          badgeColor: isRec ? 'bg-sky-50 text-sky-700 border border-sky-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200',
        };
      }),
    [staffCandidates]
  );

  const modalShiftOptions: SelectOption[] = useMemo(
    () =>
      shiftTypes.map((st) => ({
        value: st.id,
        label: `${st.shift_name} (${st.start_time.slice(0, 5)} - ${st.end_time.slice(0, 5)})`,
        icon: getShiftIcon(st.shift_code),
        badge: st.is_overnight ? 'Qua đêm' : undefined,
      })),
    [shiftTypes]
  );

  const modalStatusOptions: SelectOption[] = useMemo(
    () => [
      { value: 'SCHEDULED', label: 'SCHEDULED (Đã lên lịch)', icon: <Clock className="w-3.5 h-3.5 text-blue-500" /> },
      { value: 'COMPLETED', label: 'COMPLETED (Hoàn thành)', icon: <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" /> },
      { value: 'CANCELLED', label: 'CANCELLED (Hủy ca)', icon: <XCircle className="w-3.5 h-3.5 text-rose-500" /> },
    ],
    []
  );

  const todayStr = formatDateString(new Date());

  // ================= RENDER =================
  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* ================= REALTIME TOAST ================= */}
      {realtimeToast && (
        <div className="fixed top-20 right-6 z-50 flex items-center gap-3 px-4 py-3 bg-slate-900/90 text-white text-xs font-semibold rounded-xl shadow-2xl backdrop-blur-md border border-white/20 animate-in fade-in slide-in-from-top-4 duration-300">
          <Sparkles className="w-4 h-4 text-sky-400 animate-spin" />
          <span>{realtimeToast.message}</span>
          <button
            onClick={() => setRealtimeToast(null)}
            className="ml-2 text-slate-400 hover:text-white"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* ================= HEADER SECTION ================= */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white/80 backdrop-blur-xl p-5 rounded-2xl border border-slate-200/80 shadow-xs">
        <div>
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center justify-center p-2 rounded-xl bg-sky-50 text-sky-600 border border-sky-100">
              <CalendarDays className="w-5 h-5" />
            </span>
            <div>
              <h1 className="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight">
                Quản lý Xếp ca trực Lễ tân & Bảo vệ
              </h1>
              <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
                Xem và điều phối lịch làm việc theo tháng, kiểm tra trùng ca tự động & đồng bộ tức thì.
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2.5 flex-wrap">
          {/* Nút Tạo ca mới */}
          <button
            onClick={() => handleOpenCreateModal()}
            className="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold shadow-md shadow-sky-500/20 active:scale-95 transition-all"
          >
            <Plus className="w-4 h-4" />
            <span>Phân ca trực mới</span>
          </button>

          {/* Nút Refresh */}
          <button
            onClick={() => fetchSchedules(true)}
            disabled={isRefreshing}
            title="Làm mới dữ liệu"
            className="p-2 rounded-xl text-slate-600 hover:text-slate-900 bg-slate-100/80 hover:bg-slate-200/80 border border-slate-200 transition-all active:scale-95"
          >
            <RefreshCw className={`w-4 h-4 ${isRefreshing ? 'animate-spin text-sky-600' : ''}`} />
          </button>
        </div>
      </div>

      {/* ================= KPI STATS CARDS ================= */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div className="bg-white/80 backdrop-blur-md p-3.5 rounded-2xl border border-slate-200/80 shadow-2xs">
          <span className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Tổng số ca</span>
          <div className="text-xl font-extrabold text-slate-900 mt-1">{summary.total_shifts}</div>
          <span className="text-[10px] text-slate-400">Trong kỳ đang xem</span>
        </div>

        <div className="bg-white/80 backdrop-blur-md p-3.5 rounded-2xl border border-slate-200/80 shadow-2xs">
          <span className="text-[11px] font-bold text-sky-700 uppercase tracking-wider flex items-center gap-1">
            <UserCheck className="w-3 h-3 text-sky-600" /> Ca Lễ tân
          </span>
          <div className="text-xl font-extrabold text-sky-950 mt-1">{summary.reception_shifts}</div>
          <span className="text-[10px] text-sky-600/70">Bộ phận Lễ tân</span>
        </div>

        <div className="bg-white/80 backdrop-blur-md p-3.5 rounded-2xl border border-slate-200/80 shadow-2xs">
          <span className="text-[11px] font-bold text-emerald-700 uppercase tracking-wider flex items-center gap-1">
            <Shield className="w-3 h-3 text-emerald-600" /> Ca Bảo vệ
          </span>
          <div className="text-xl font-extrabold text-emerald-950 mt-1">{summary.security_shifts}</div>
          <span className="text-[10px] text-emerald-600/70">Bộ phận An ninh</span>
        </div>

        <div
          onClick={() => setStatusFilter(statusFilter === 'SCHEDULED' ? 'ALL' : 'SCHEDULED')}
          className={`bg-white/80 backdrop-blur-md p-3.5 rounded-2xl border shadow-2xs cursor-pointer transition-all hover:scale-102 ${
            statusFilter === 'SCHEDULED'
              ? 'border-blue-500 ring-2 ring-blue-500/20 bg-blue-50/50'
              : 'border-slate-200/80 hover:border-slate-300'
          }`}
          title="Bấm để lọc ca Đã lên lịch"
        >
          <span className="text-[11px] font-bold text-blue-700 uppercase tracking-wider flex items-center justify-between">
            <span>Đã lên lịch</span>
            {statusFilter === 'SCHEDULED' && <Check className="w-3 h-3 text-blue-600" />}
          </span>
          <div className="text-xl font-extrabold text-blue-950 mt-1">{summary.scheduled_count}</div>
          <span className="text-[10px] text-blue-600/70">Sẵn sàng vận hành</span>
        </div>

        <div
          onClick={() => setStatusFilter(statusFilter === 'COMPLETED' ? 'ALL' : 'COMPLETED')}
          className={`bg-white/80 backdrop-blur-md p-3.5 rounded-2xl border shadow-2xs cursor-pointer transition-all hover:scale-102 ${
            statusFilter === 'COMPLETED'
              ? 'border-emerald-500 ring-2 ring-emerald-500/20 bg-emerald-50/50'
              : 'border-slate-200/80 hover:border-slate-300'
          }`}
          title="Bấm để lọc ca Đã hoàn thành"
        >
          <span className="text-[11px] font-bold text-emerald-700 uppercase tracking-wider flex items-center justify-between">
            <span>Đã hoàn thành</span>
            {statusFilter === 'COMPLETED' && <Check className="w-3 h-3 text-emerald-600" />}
          </span>
          <div className="text-xl font-extrabold text-emerald-950 mt-1">{summary.completed_count}</div>
          <span className="text-[10px] text-emerald-600/70">Đã chốt trực</span>
        </div>

        <div
          onClick={() => setStatusFilter(statusFilter === 'CANCELLED' ? 'ALL' : 'CANCELLED')}
          className={`bg-white/80 backdrop-blur-md p-3.5 rounded-2xl border shadow-2xs cursor-pointer transition-all hover:scale-102 ${
            statusFilter === 'CANCELLED'
              ? 'border-rose-500 ring-2 ring-rose-500/20 bg-rose-50/50'
              : 'border-slate-200/80 hover:border-slate-300'
          }`}
          title="Bấm để lọc ca Đã hủy"
        >
          <span className="text-[11px] font-bold text-rose-700 uppercase tracking-wider flex items-center justify-between">
            <span>Đã hủy</span>
            {statusFilter === 'CANCELLED' && <Check className="w-3 h-3 text-rose-600" />}
          </span>
          <div className="text-xl font-extrabold text-rose-950 mt-1">{summary.cancelled_count}</div>
          <span className="text-[10px] text-rose-600/70">Ca không trực</span>
        </div>
      </div>

      {/* ================= CONTROLS & FILTER BAR ================= */}
      <div className="relative z-30 bg-white/85 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs space-y-3">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
          {/* Bộ chuyển Tuần / Tháng */}
          <div className="flex items-center gap-2">
            <button
              onClick={handlePrev}
              className="p-1.5 rounded-xl hover:bg-slate-100 text-slate-700 border border-slate-200 transition-colors"
              title="Kỳ trước"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={handleToday}
              className="px-3 py-1.5 rounded-xl hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-bold transition-colors"
            >
              Hôm nay
            </button>
            <button
              onClick={handleNext}
              className="p-1.5 rounded-xl hover:bg-slate-100 text-slate-700 border border-slate-200 transition-colors"
              title="Kỳ kế tiếp"
            >
              <ChevronRight className="w-4 h-4" />
            </button>

            {/* Hiển thị tiêu đề kỳ đang xem */}
            <div className="ml-2 font-extrabold text-slate-800 text-sm sm:text-base flex items-center gap-2">
              <Calendar className="w-4 h-4 text-sky-600" />
              <span>
                Tháng {currentDate.getMonth() + 1} / {currentDate.getFullYear()}
              </span>
            </div>
          </div>

          {/* Quick Search */}
          <div className="relative w-full lg:w-72">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Tìm theo tên, mã NV, vị trí..."
              className="w-full pl-9 pr-8 py-1.5 rounded-xl text-xs bg-slate-50 border border-slate-200 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-sky-500 transition-all"
            />
            {searchQuery && (
              <button
                onClick={() => setSearchQuery('')}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        </div>

        {/* Dropdown Filters - Chuẩn Style Combobox Đồng bộ */}
        <div className="flex items-center gap-2.5 flex-wrap pt-2 border-t border-slate-100 text-xs">
          <div className="flex items-center gap-1.5 text-slate-500 font-bold shrink-0">
            <Filter className="w-3.5 h-3.5 text-indigo-600" />
            <span>Lọc:</span>
          </div>

          {/* Role Filter */}
          <div className="min-w-[210px]">
            <CustomSelect
              value={roleFilter}
              onChange={(val) => setRoleFilter(val)}
              options={roleFilterOptions}
              searchable={false}
              prefixIcon={<Users className="w-3.5 h-3.5" />}
            />
          </div>

          {/* Staff Filter */}
          <div className="min-w-[230px]">
            <CustomSelect
              value={staffFilter}
              onChange={(val) => setStaffFilter(val)}
              options={staffFilterOptions}
              searchable={true}
              searchPlaceholder="Tìm nhân viên..."
              prefixIcon={<UserCheck className="w-3.5 h-3.5" />}
            />
          </div>

          {/* Shift Type Filter */}
          <div className="min-w-[210px]">
            <CustomSelect
              value={shiftFilter}
              onChange={(val) => setShiftFilter(val)}
              options={shiftFilterOptions}
              searchable={false}
              prefixIcon={<Clock className="w-3.5 h-3.5" />}
            />
          </div>

          {/* Status Filter */}
          <div className="min-w-[180px]">
            <CustomSelect
              value={statusFilter}
              onChange={(val) => setStatusFilter(val)}
              options={statusFilterOptions}
              searchable={false}
              prefixIcon={<Filter className="w-3.5 h-3.5" />}
            />
          </div>

          {/* Reset button if filtered */}
          {(roleFilter !== 'ALL' || staffFilter !== 'ALL' || shiftFilter !== 'ALL' || statusFilter !== 'ALL' || searchQuery) && (
            <button
              onClick={() => {
                setRoleFilter('ALL');
                setStaffFilter('ALL');
                setShiftFilter('ALL');
                setStatusFilter('ALL');
                setSearchQuery('');
              }}
              className="text-xs text-indigo-600 hover:text-indigo-800 font-bold ml-auto"
            >
              Xóa bộ lọc
            </button>
          )}
        </div>
      </div>

      {/* ================= ERROR ALERT ================= */}
      {errorMsg && (
        <div className="flex items-center gap-3 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
          <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
          <span>{errorMsg}</span>
          <button onClick={() => fetchSchedules()} className="ml-auto underline font-bold">
            Thử lại
          </button>
        </div>
      )}

      {/* ================= CALENDAR MAIN VIEW ================= */}
      {isLoading ? (
        <div className="p-12 text-center bg-white/70 backdrop-blur-md rounded-2xl border border-slate-200 flex flex-col items-center justify-center">
          <RefreshCw className="w-8 h-8 text-sky-500 animate-spin mb-3" />
          <span className="text-sm font-bold text-slate-700">Đang tải lịch trực...</span>
          <span className="text-xs text-slate-400 mt-1">Đang tối ưu dữ liệu và quan hệ nhân sự</span>
        </div>
      ) : (
        /* ================= MONTH VIEW (THEO THÁNG) ================= */
        <div className="bg-white/85 backdrop-blur-xl rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
          {/* Day of Week Headers */}
          <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80 text-center font-bold text-xs text-slate-600">
            {['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ Nhật'].map((dayName) => (
              <div key={dayName} className="py-2.5 border-r last:border-r-0 border-slate-200">
                {dayName}
              </div>
            ))}
          </div>

          {/* Weeks Grid */}
          <div className="divide-y divide-slate-200">
            {activeDateRange.weeks?.map((week, wIdx) => (
              <div key={wIdx} className="grid grid-cols-7 divide-x divide-slate-200">
                {week.map((dayDate) => {
                  const dateKey = formatDateString(dayDate);
                  const isToday = dateKey === todayStr;
                  const isCurrentMonth = dayDate.getMonth() === currentDate.getMonth();
                  const daySchedules = schedulesByDate.get(dateKey) || [];

                  // Đếm Lễ tân & Bảo vệ trong ngày
                  let recCount = 0;
                  let secCount = 0;
                  let completedCount = 0;
                  let cancelledCount = 0;
                  daySchedules.forEach((s) => {
                    const roles = s.staff_profile?.user?.roles?.map((r) => r.role_code) || [];
                    if (roles.includes('RECEPTIONIST')) recCount++;
                    if (roles.includes('SECURITY_GUARD')) secCount++;
                    if (s.status === 'COMPLETED') completedCount++;
                    else if (s.status === 'CANCELLED') cancelledCount++;
                  });

                  return (
                    <div
                      key={dateKey}
                      onClick={() => setSelectedDayDetail(dateKey)}
                      className={`min-h-[110px] p-2 flex flex-col justify-between cursor-pointer transition-colors ${
                        !isCurrentMonth
                          ? 'bg-slate-50/50 text-slate-400'
                          : isToday
                          ? 'bg-sky-50/40 text-slate-900 font-bold'
                          : 'bg-white hover:bg-slate-50/80 text-slate-800'
                      }`}
                    >
                      {/* Date Header */}
                      <div className="flex items-center justify-between">
                        <span
                          className={`inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-extrabold ${
                            isToday ? 'bg-sky-600 text-white' : 'text-slate-800'
                          }`}
                        >
                          {dayDate.getDate()}
                        </span>

                        {daySchedules.length > 0 && (
                          <span className="text-[10px] font-bold text-slate-400">
                            {daySchedules.length} ca
                          </span>
                        )}
                      </div>

                      {/* Badges / Chips */}
                      <div className="space-y-1 my-1">
                        {recCount > 0 && (
                          <div className="flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-200 truncate">
                            <UserCheck className="w-2.5 h-2.5 text-sky-600" />
                            <span>{recCount} Lễ tân</span>
                          </div>
                        )}
                        {secCount > 0 && (
                          <div className="flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 truncate">
                            <Shield className="w-2.5 h-2.5 text-emerald-600" />
                            <span>{secCount} Bảo vệ</span>
                          </div>
                        )}
                        {completedCount > 0 && (
                          <div className="flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 truncate">
                            <CheckCircle2 className="w-2.5 h-2.5 text-emerald-600" />
                            <span>{completedCount} hoàn thành</span>
                          </div>
                        )}
                        {cancelledCount > 0 && (
                          <div className="flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200 truncate">
                            <XCircle className="w-2.5 h-2.5 text-rose-600" />
                            <span>{cancelledCount} đã hủy</span>
                          </div>
                        )}
                      </div>

                      {/* Footer Hint */}
                      <div className="text-[9px] text-slate-400 text-right opacity-0 hover:opacity-100 transition-opacity">
                        Xem chi tiết &rarr;
                      </div>
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ================= MODAL CREATE / EDIT SCHEDULE ================= */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in zoom-in-95 duration-200">
            {/* Modal Header */}
            <div className="px-6 py-4 bg-gradient-to-r from-sky-600 to-indigo-600 text-white flex items-center justify-between">
              <div className="flex items-center gap-2">
                <CalendarDays className="w-5 h-5" />
                <h2 className="text-base font-extrabold">
                  {editingSchedule ? 'Chỉnh sửa Ca trực' : 'Phân công Ca trực mới'}
                </h2>
              </div>
              <button
                onClick={() => setIsModalOpen(false)}
                className="p-1 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Modal Form */}
            <form onSubmit={handleSaveSchedule} className="p-6 space-y-4">
              {/* Conflict Error Alert */}
              {modalError && (
                <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-start gap-2.5">
                  <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                  <div className="space-y-1">
                    <div className="font-bold">Không thể lưu ca trực:</div>
                    <div>{modalError}</div>
                  </div>
                </div>
              )}

              {/* Cảnh báo trùng lịch trực sớm (Client-side Conflict Detection) */}
              {existingStaffShiftOnModalDate && (
                <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2.5">
                  <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                  <div className="space-y-1">
                    <div className="font-bold">
                      Nhân viên này đã có ca trực trong ngày {modalDate}:
                    </div>
                    <div className="text-amber-800">
                      Ca: <strong>{existingStaffShiftOnModalDate.shift_type?.shift_name || 'Đã lên lịch'}</strong>
                      {existingStaffShiftOnModalDate.assigned_location && (
                        <span> — Vị trí: {existingStaffShiftOnModalDate.assigned_location}</span>
                      )}
                    </div>
                    <div className="pt-1">
                      <button
                        type="button"
                        onClick={() => handleOpenEditModal(existingStaffShiftOnModalDate)}
                        className="text-sky-700 hover:text-sky-900 font-bold underline inline-flex items-center gap-1"
                      >
                        <Edit2 className="w-3 h-3" /> Chuyển sang chỉnh sửa ca trực này
                      </button>
                    </div>
                  </div>
                </div>
              )}

              {/* 1. Chọn Nhân viên */}
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Nhân viên trực <span className="text-rose-500">*</span>
                </label>
                <CustomSelect
                  overlay
                  value={modalStaffId}
                  onChange={(val) => setModalStaffId(val)}
                  options={modalStaffOptions}
                  placeholder="-- Chọn nhân viên Lễ tân hoặc Bảo vệ --"
                  searchable={true}
                  searchPlaceholder="Tìm theo tên hoặc mã nhân viên..."
                  prefixIcon={<UserCheck className="w-3.5 h-3.5 text-slate-400" />}
                />
              </div>

              {/* 2. Chọn Ca trực & Ngày */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1.5">
                    Loại ca trực <span className="text-rose-500">*</span>
                  </label>
                  <CustomSelect
                    overlay
                    value={modalShiftId}
                    onChange={(val) => setModalShiftId(val)}
                    options={modalShiftOptions}
                    placeholder="-- Chọn loại ca trực --"
                    searchable={false}
                    prefixIcon={<Clock className="w-3.5 h-3.5 text-slate-400" />}
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1.5">
                    Ngày trực <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={modalDate}
                    onChange={(e) => setModalDate(e.target.value)}
                    className="w-full px-3 py-2 rounded-xl text-xs bg-white border border-slate-200 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-indigo-500 font-semibold shadow-2xs"
                    required
                  />
                </div>
              </div>

              {/* 3. Vị trí phân công */}
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Vị trí / Địa điểm trực
                </label>
                <div className="relative">
                  <MapPin className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input
                    type="text"
                    value={modalLocation}
                    onChange={(e) => setModalLocation(e.target.value)}
                    placeholder="VD: Sảnh chính Tháp A, Bốt An Ninh Cổng 1, Tầng Hầm B1..."
                    className="w-full pl-9 pr-3 py-2 rounded-xl text-xs bg-slate-50 border border-slate-200 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-indigo-500 font-medium"
                  />
                </div>
              </div>

              {/* 4. Trạng thái ca trực */}
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Trạng thái ca
                </label>
                <CustomSelect
                  overlay
                  value={modalStatus}
                  onChange={(val) => setModalStatus(val)}
                  options={modalStatusOptions}
                  searchable={false}
                  prefixIcon={<Filter className="w-3.5 h-3.5 text-slate-400" />}
                />
                {/* Nút bấm nhanh chọn trạng thái */}
                <div className="grid grid-cols-3 gap-2 mt-2">
                  <button
                    type="button"
                    onClick={() => setModalStatus('SCHEDULED')}
                    className={`py-1.5 px-2 rounded-xl text-xs font-bold border transition-all flex items-center justify-center gap-1 ${
                      modalStatus === 'SCHEDULED'
                        ? 'bg-blue-600 text-white border-blue-600 shadow-xs'
                        : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <Clock className="w-3.5 h-3.5" />
                    <span>Đã lên lịch</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setModalStatus('COMPLETED')}
                    className={`py-1.5 px-2 rounded-xl text-xs font-bold border transition-all flex items-center justify-center gap-1 ${
                      modalStatus === 'COMPLETED'
                        ? 'bg-emerald-600 text-white border-emerald-600 shadow-xs'
                        : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <CheckCircle2 className="w-3.5 h-3.5" />
                    <span>Hoàn thành</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setModalStatus('CANCELLED')}
                    className={`py-1.5 px-2 rounded-xl text-xs font-bold border transition-all flex items-center justify-center gap-1 ${
                      modalStatus === 'CANCELLED'
                        ? 'bg-rose-600 text-white border-rose-600 shadow-xs'
                        : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <XCircle className="w-3.5 h-3.5" />
                    <span>Đã hủy</span>
                  </button>
                </div>
              </div>

              {/* 5. Ghi chú */}
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Ghi chú phân công
                </label>
                <textarea
                  value={modalNotes}
                  onChange={(e) => setModalNotes(e.target.value)}
                  placeholder="Ghi chú thêm về yêu cầu công việc hoặc lưu ý trong ca trực..."
                  rows={2}
                  className="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 border border-slate-200 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-sky-500 font-medium"
                />
              </div>

              {/* Modal Buttons */}
              <div className="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 border border-slate-200 transition-colors"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 shadow-md shadow-sky-500/20 active:scale-95 transition-all disabled:opacity-50 flex items-center gap-1.5"
                >
                  {isSubmitting ? (
                    <>
                      <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                      <span>Đang lưu...</span>
                    </>
                  ) : (
                    <>
                      <Check className="w-3.5 h-3.5" />
                      <span>{editingSchedule ? 'Lưu thay đổi' : 'Xác nhận xếp ca'}</span>
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= MODAL DELETE / CANCEL CONFIRMATION ================= */}
      {deleteConfirmSchedule && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-center gap-3 text-rose-600">
              <span className="p-2 rounded-xl bg-rose-50 border border-rose-200">
                <Trash2 className="w-6 h-6" />
              </span>
              <div>
                <h3 className="text-base font-extrabold text-slate-900">
                  Xóa hoặc Hủy ca trực?
                </h3>
                <p className="text-xs text-slate-500">
                  Lịch trực ngày {parseScheduleDateKey(deleteConfirmSchedule.work_date)} của{' '}
                  <span className="font-bold text-slate-800">
                    {deleteConfirmSchedule.staff_profile?.user?.full_name || 'nhân viên'}
                  </span>
                </p>
              </div>
            </div>

            <p className="text-xs text-slate-600 bg-slate-50 p-3 rounded-xl border border-slate-200 leading-relaxed">
              Bạn có thể chọn <strong>Hủy ca</strong> (giữ lại bản ghi với trạng thái CANCELLED để phục vụ thống kê báo cáo) hoặc <strong>Xóa hẳn</strong> nếu ca được xếp nhầm.
            </p>

            <div className="flex items-center justify-end gap-2.5 pt-3">
              <button
                type="button"
                onClick={() => setDeleteConfirmSchedule(null)}
                disabled={isDeleting !== null}
                className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-bold text-slate-600 hover:bg-slate-100 border border-slate-200 transition ${
                  isDeleting !== null ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'
                }`}
              >
                Đóng
              </button>

              <button
                type="button"
                onClick={() => handleConfirmDeleteOrCancel('cancel')}
                disabled={isDeleting !== null}
                className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 ${
                  isDeleting === 'cancel'
                    ? 'bg-amber-100 text-amber-800 border border-amber-300 cursor-wait shadow-inner'
                    : isDeleting === 'delete'
                    ? 'opacity-40 cursor-not-allowed bg-amber-50 text-amber-700 border border-amber-200'
                    : 'text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 cursor-pointer shadow-xs hover:scale-101'
                }`}
              >
                {isDeleting === 'cancel' ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin text-amber-600 shrink-0" />
                    <span>Đang hủy ca...</span>
                  </>
                ) : (
                  <span>Hủy ca (CANCELLED)</span>
                )}
              </button>

              <button
                type="button"
                onClick={() => handleConfirmDeleteOrCancel('delete')}
                disabled={isDeleting !== null}
                className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 ${
                  isDeleting === 'delete'
                    ? 'bg-rose-700 text-white shadow-inner cursor-wait'
                    : isDeleting === 'cancel'
                    ? 'opacity-40 cursor-not-allowed bg-rose-600 text-white'
                    : 'text-white bg-rose-600 hover:bg-rose-700 shadow-md shadow-rose-500/20 cursor-pointer hover:scale-101'
                }`}
              >
                {isDeleting === 'delete' ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin text-white shrink-0" />
                    <span>Đang xóa hoàn toàn...</span>
                  </>
                ) : (
                  <span>Xóa hoàn toàn</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MONTH VIEW DAY DETAIL MODAL ================= */}
      {selectedDayDetail && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-950/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-[540px] overflow-hidden animate-in zoom-in-95 duration-200 flex flex-col">
            {/* Header cố định gọn gàng */}
            <div className="px-4 py-2.5 bg-gradient-to-r from-sky-600 to-indigo-600 text-white flex items-center justify-between shrink-0">
              <div>
                <h3 className="text-xs sm:text-sm font-bold flex items-center gap-1.5 whitespace-nowrap">
                  <Calendar className="w-4 h-4 shrink-0" />
                  <span>Chi tiết ca trực ngày {formatDisplayDate(selectedDayDetail)}</span>
                </h3>
                <span className="text-[10px] sm:text-[11px] text-sky-100 font-medium">
                  {schedulesByDate.get(selectedDayDetail)?.length || 0} ca trực được phân công
                </span>
              </div>
              <div className="flex items-center gap-1.5">
                {/* Nút hoàn thành/hủy hàng loạt trong ngày */}
                {(() => {
                  const dayItems = schedulesByDate.get(selectedDayDetail) || [];
                  const scheduledCount = dayItems.filter((s) => s.status === 'SCHEDULED').length;
                  if (scheduledCount === 0) return null;
                  return (
                    <div className="flex items-center gap-1 mr-1">
                      <button
                        type="button"
                        onClick={() => handleBatchDayStatus(selectedDayDetail, 'COMPLETED')}
                        disabled={isUpdatingStatus !== null}
                        className="px-2 py-0.5 rounded-md text-[11px] font-semibold bg-emerald-500/90 hover:bg-emerald-500 text-white flex items-center gap-1 transition shadow-xs cursor-pointer disabled:opacity-60 disabled:cursor-wait"
                        title="Đánh dấu tất cả ca chờ trực sang Hoàn thành"
                      >
                        {isUpdatingStatus === 'batch' ? (
                          <RefreshCw className="w-3 h-3 animate-spin text-white" />
                        ) : (
                          <CheckCheck className="w-3 h-3" />
                        )}
                        <span>Hoàn thành ({scheduledCount})</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => handleBatchDayStatus(selectedDayDetail, 'CANCELLED')}
                        disabled={isUpdatingStatus !== null}
                        className="px-2 py-0.5 rounded-md text-[11px] font-semibold bg-rose-500/90 hover:bg-rose-500 text-white flex items-center gap-1 transition shadow-xs cursor-pointer disabled:opacity-60 disabled:cursor-wait"
                        title="Hủy tất cả ca chờ trực trong ngày"
                      >
                        {isUpdatingStatus === 'batch' ? (
                          <RefreshCw className="w-3 h-3 animate-spin text-white" />
                        ) : (
                          <XCircle className="w-3 h-3" />
                        )}
                        <span>Hủy ({scheduledCount})</span>
                      </button>
                    </div>
                  );
                })()}

                <button
                  onClick={() => setSelectedDayDetail(null)}
                  className="p-1 rounded-md text-white/80 hover:text-white hover:bg-white/10 transition cursor-pointer"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* Danh sách ca trực: Tinh gọn, hiển thị vừa đủ 3 ca không mất chữ + Thanh cuộn khi có từ 4 ca */}
            <div className="p-2.5 sm:p-3 pr-2 max-h-[195px] overflow-y-auto visible-scrollbar space-y-2">
              {(schedulesByDate.get(selectedDayDetail) || []).length === 0 ? (
                <div className="p-6 text-center text-slate-400">
                  <Clock className="w-7 h-7 opacity-40 mx-auto mb-1.5" />
                  <p className="text-xs font-medium">Chưa có ca trực nào được phân công trong ngày này.</p>
                </div>
              ) : (
                schedulesByDate.get(selectedDayDetail)!.map((item) => (
                  <div
                    key={item.id}
                    className="p-2 sm:p-2.5 rounded-xl border border-slate-200 bg-slate-50/60 hover:bg-white hover:border-slate-300 hover:shadow-2xs transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs"
                  >
                    <div className="space-y-0.5 flex-1 min-w-0">
                      <div className="flex items-center gap-1.5 flex-wrap">
                        {getShiftIcon(item.shift_type?.shift_code || '')}
                        <span className="font-bold text-slate-900 text-xs">
                          {item.shift_type?.shift_name}
                        </span>
                        {getRoleBadge(item.staff_profile?.user?.roles)}
                        {getStatusBadge(item.status)}
                      </div>

                      <div className="text-[11px] text-slate-600 flex items-center gap-1.5 flex-wrap">
                        <span className="font-semibold text-slate-800">
                          {item.staff_profile?.user?.full_name} ({item.staff_profile?.employee_code})
                        </span>
                        <span className="text-slate-300">•</span>
                        <span className="font-mono font-semibold text-slate-700 bg-slate-100 px-1 py-0.5 rounded text-[10px]">
                          {item.shift_type?.start_time.slice(0, 5)} - {item.shift_type?.end_time.slice(0, 5)}
                        </span>
                        {item.assigned_location && (
                          <>
                            <span className="text-slate-300">•</span>
                            <span className="flex items-center gap-1 text-slate-500 text-[10px]">
                              <MapPin className="w-2.5 h-2.5 text-slate-400 shrink-0" />
                              {item.assigned_location}
                            </span>
                          </>
                        )}
                      </div>
                      {item.notes && (
                        <div className="text-[10px] text-slate-500 italic mt-0.5 flex items-center gap-1">
                          <FileText className="w-2.5 h-2.5 text-slate-400 shrink-0" />
                          <span>{item.notes}</span>
                        </div>
                      )}
                    </div>

                    <div className="flex items-center gap-1 shrink-0 flex-wrap justify-end pt-1 sm:pt-0 border-t sm:border-t-0 border-slate-100">
                      {/* Nút Hoàn thành ca trực */}
                      {item.status === 'SCHEDULED' && (
                        <button
                          type="button"
                          onClick={() => handleQuickUpdateStatus(item.id, 'COMPLETED')}
                          disabled={isUpdatingStatus === item.id}
                          className="px-2 py-0.5 rounded-lg text-[11px] font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 flex items-center gap-1 transition-all shadow-2xs hover:scale-102 cursor-pointer disabled:opacity-75 disabled:cursor-wait"
                          title="Đánh dấu đã hoàn thành ca trực"
                        >
                          {isUpdatingStatus === item.id ? (
                            <>
                              <RefreshCw className="w-3 h-3 text-emerald-600 animate-spin shrink-0" />
                              <span>Đang lưu...</span>
                            </>
                          ) : (
                            <>
                              <CheckCircle2 className="w-3 h-3 text-emerald-600 shrink-0" />
                              <span>Hoàn thành</span>
                            </>
                          )}
                        </button>
                      )}

                      {/* Nút Hủy ca trực */}
                      {item.status === 'SCHEDULED' && (
                        <button
                          type="button"
                          onClick={() => handleQuickUpdateStatus(item.id, 'CANCELLED')}
                          disabled={isUpdatingStatus === item.id}
                          className="px-2 py-0.5 rounded-lg text-[11px] font-bold text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 flex items-center gap-1 transition-all shadow-2xs hover:scale-102 cursor-pointer disabled:opacity-75 disabled:cursor-wait"
                          title="Hủy bỏ ca trực này"
                        >
                          {isUpdatingStatus === item.id ? (
                            <>
                              <RefreshCw className="w-3 h-3 text-rose-600 animate-spin shrink-0" />
                              <span>Đang hủy...</span>
                            </>
                          ) : (
                            <>
                              <XCircle className="w-3 h-3 text-rose-600 shrink-0" />
                              <span>Hủy ca</span>
                            </>
                          )}
                        </button>
                      )}

                      {/* Khi đã Hoàn thành: Có thể Đặt lại về Chờ trực */}
                      {item.status === 'COMPLETED' && (
                        <button
                          type="button"
                          onClick={() => handleQuickUpdateStatus(item.id, 'SCHEDULED')}
                          disabled={isUpdatingStatus === item.id}
                          className="px-2 py-0.5 rounded-lg text-[10px] font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200 flex items-center gap-1 transition cursor-pointer disabled:opacity-75 disabled:cursor-wait"
                          title="Đặt lại về trạng thái Đã lên lịch"
                        >
                          {isUpdatingStatus === item.id ? (
                            <>
                              <RefreshCw className="w-2.5 h-2.5 text-blue-500 animate-spin shrink-0" />
                              <span>Đang lưu...</span>
                            </>
                          ) : (
                            <>
                              <Clock className="w-2.5 h-2.5 text-blue-500 shrink-0" />
                              <span>Đặt lại</span>
                            </>
                          )}
                        </button>
                      )}

                      {/* Khi Đã hủy: Có thể Khôi phục ca */}
                      {item.status === 'CANCELLED' && (
                        <button
                          type="button"
                          onClick={() => handleQuickUpdateStatus(item.id, 'SCHEDULED')}
                          disabled={isUpdatingStatus === item.id}
                          className="px-2 py-0.5 rounded-lg text-[11px] font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 flex items-center gap-1 transition shadow-2xs hover:scale-102 cursor-pointer disabled:opacity-75 disabled:cursor-wait"
                          title="Khôi phục ca trực về trạng thái Đã lên lịch"
                        >
                          {isUpdatingStatus === item.id ? (
                            <>
                              <RefreshCw className="w-2.5 h-2.5 text-blue-600 animate-spin shrink-0" />
                              <span>Đang khôi phục...</span>
                            </>
                          ) : (
                            <>
                              <RefreshCw className="w-2.5 h-2.5 text-blue-600 shrink-0" />
                              <span>Khôi phục</span>
                            </>
                          )}
                        </button>
                      )}

                      {/* Edit Button */}
                      <button
                        onClick={() => {
                          setSelectedDayDetail(null);
                          handleOpenEditModal(item);
                        }}
                        className="p-1 rounded-lg hover:bg-slate-200 text-slate-500 hover:text-slate-800 transition cursor-pointer"
                        title="Chỉnh sửa ca trực"
                      >
                        <Edit2 className="w-3.5 h-3.5" />
                      </button>

                      {/* Delete Button */}
                      <button
                        onClick={() => {
                          setSelectedDayDetail(null);
                          setDeleteConfirmSchedule(item);
                        }}
                        className="p-1 rounded-lg hover:bg-rose-100 text-rose-500 hover:text-rose-700 transition cursor-pointer"
                        title="Xóa vĩnh viễn"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>

            {/* Footer cố định gọn gàng */}
            <div className="px-4 py-2 bg-slate-50 border-t border-slate-200 flex items-center justify-between shrink-0">
              <button
                onClick={() => {
                  const targetDay = selectedDayDetail;
                  setSelectedDayDetail(null);
                  handleOpenCreateModal(targetDay);
                }}
                className="flex items-center gap-1 px-3 py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-xs font-bold transition shadow-xs cursor-pointer"
              >
                <Plus className="w-3.5 h-3.5" />
                Thêm ca
              </button>

              <button
                onClick={() => setSelectedDayDetail(null)}
                className="px-3.5 py-1 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-100 transition cursor-pointer"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ManagementStaffSchedulePanel;
