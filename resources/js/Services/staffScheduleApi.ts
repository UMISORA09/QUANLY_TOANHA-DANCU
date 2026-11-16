/**
 * API Client riêng cho chức năng Quản lý Xếp ca trực (Module #12)
 */

export interface ShiftType {
  id: string;
  shift_code: string;
  shift_name: string;
  start_time: string;
  end_time: string;
  break_minutes: number;
  is_overnight: boolean;
}

export interface StaffProfileItem {
  id: string;
  employee_code: string;
  job_title: string;
  department_id: string;
  user?: {
    id: string;
    full_name: string;
    username: string;
    email: string;
    phone_number?: string;
    roles?: Array<{ id: string; role_code: string; role_name: string }>;
  };
  department?: {
    id: string;
    department_code: string;
    department_name: string;
  };
}

export interface StaffScheduleItem {
  id: string;
  staff_profile_id: string;
  shift_type_id: string;
  work_date: string;
  assigned_location?: string | null;
  status: 'SCHEDULED' | 'COMPLETED' | 'ABSENT' | 'SWAPPED' | 'CANCELLED';
  notes?: string | null;
  created_at: string;
  staff_profile?: StaffProfileItem;
  shift_type?: ShiftType;
  creator?: {
    id: string;
    full_name: string;
  };
}

export interface ScheduleSummary {
  total_shifts: number;
  reception_shifts: number;
  security_shifts: number;
  scheduled_count: number;
  completed_count: number;
  cancelled_count: number;
  start_date: string;
  end_date: string;
}

export interface ScheduleListResponse {
  success: boolean;
  data: StaffScheduleItem[];
  summary: ScheduleSummary;
  message?: string;
}

const getAuthHeaders = (): Record<string, string> => {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  const token =
    localStorage.getItem('smart_cassavas_token') ||
    sessionStorage.getItem('smart_cassavas_token') ||
    localStorage.getItem('smart_token') ||
    sessionStorage.getItem('smart_token') ||
    '';

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const rawUser = localStorage.getItem('smart_cassavas_user') || sessionStorage.getItem('smart_cassavas_user');
  if (rawUser) {
    try {
      const u = JSON.parse(rawUser);
      if (u.id || u.user_id) {
        headers['X-User-Id'] = u.id || u.user_id;
      }
    } catch {
      // ignore
    }
  }

  return headers;
};

export const staffScheduleApi = {
  /**
   * Lấy danh sách lịch trực theo khoảng thời gian và bộ lọc
   */
  async getSchedules(filters: {
    start_date?: string;
    end_date?: string;
    role?: string;
    staff_profile_id?: string;
    shift_type_id?: string;
    status?: string;
    search?: string;
  }): Promise<ScheduleListResponse> {
    const params = new URLSearchParams();
    if (filters.start_date) params.append('start_date', filters.start_date);
    if (filters.end_date) params.append('end_date', filters.end_date);
    if (filters.role && filters.role !== 'ALL') params.append('role', filters.role);
    if (filters.staff_profile_id && filters.staff_profile_id !== 'ALL') {
      params.append('staff_profile_id', filters.staff_profile_id);
    }
    if (filters.shift_type_id && filters.shift_type_id !== 'ALL') {
      params.append('shift_type_id', filters.shift_type_id);
    }
    if (filters.status && filters.status !== 'ALL') params.append('status', filters.status);
    if (filters.search) params.append('search', filters.search);

    const res = await fetch(`/api/v1/management/schedules?${params.toString()}`, {
      method: 'GET',
      headers: getAuthHeaders(),
    });

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || `Lỗi tải lịch trực (${res.status})`);
    }

    return res.json();
  },

  /**
   * Lấy danh mục các loại ca trực
   */
  async getShiftTypes(): Promise<ShiftType[]> {
    const res = await fetch('/api/v1/management/schedules/shift-types', {
      method: 'GET',
      headers: getAuthHeaders(),
    });

    if (!res.ok) {
      throw new Error('Không thể tải danh sách loại ca trực');
    }

    const data = await res.json();
    return data.data || [];
  },

  /**
   * Lấy danh sách nhân viên ứng viên (Lễ tân & Bảo vệ)
   */
  async getStaffCandidates(roleFilter?: string): Promise<StaffProfileItem[]> {
    const url = roleFilter && roleFilter !== 'ALL'
      ? `/api/v1/management/schedules/staff-candidates?role=${roleFilter}`
      : '/api/v1/management/schedules/staff-candidates';

    const res = await fetch(url, {
      method: 'GET',
      headers: getAuthHeaders(),
    });

    if (!res.ok) {
      throw new Error('Không thể tải danh sách nhân viên ứng viên');
    }

    const data = await res.json();
    return data.data || [];
  },

  /**
   * Phân công ca trực mới
   */
  async createSchedule(payload: {
    staff_profile_id: string;
    shift_type_id: string;
    work_date: string;
    assigned_location?: string;
    notes?: string;
    status?: string;
  }): Promise<{ success: boolean; data: StaffScheduleItem; message?: string }> {
    const res = await fetch('/api/v1/management/schedules', {
      method: 'POST',
      headers: getAuthHeaders(),
      body: JSON.stringify(payload),
    });

    const result = await res.json().catch(() => ({}));

    if (!res.ok) {
      const errorMsg = result.message || 'Không thể tạo ca trực';
      const error: any = new Error(errorMsg);
      error.status = res.status;
      error.errors = result.errors;
      throw error;
    }

    return result;
  },

  /**
   * Cập nhật thông tin ca trực
   */
  async updateSchedule(
    id: string,
    payload: {
      staff_profile_id?: string;
      shift_type_id?: string;
      work_date?: string;
      assigned_location?: string;
      notes?: string;
      status?: string;
    }
  ): Promise<{ success: boolean; data: StaffScheduleItem; message?: string }> {
    const res = await fetch(`/api/v1/management/schedules/${id}`, {
      method: 'PUT',
      headers: getAuthHeaders(),
      body: JSON.stringify(payload),
    });

    const result = await res.json().catch(() => ({}));

    if (!res.ok) {
      const errorMsg = result.message || 'Không thể cập nhật ca trực';
      const error: any = new Error(errorMsg);
      error.status = res.status;
      error.errors = result.errors;
      throw error;
    }

    return result;
  },

  /**
   * Xóa hoặc hủy ca trực
   */
  async deleteOrCancelSchedule(
    id: string,
    mode: 'delete' | 'cancel' = 'delete'
  ): Promise<{ success: boolean; message: string; data: any }> {
    const res = await fetch(`/api/v1/management/schedules/${id}?mode=${mode}`, {
      method: 'DELETE',
      headers: getAuthHeaders(),
      body: JSON.stringify({ mode }),
    });

    const result = await res.json().catch(() => ({}));

    if (!res.ok) {
      const error: any = new Error(result.message || 'Không thể xóa hoặc hủy ca trực');
      error.status = res.status;
      throw error;
    }

    return result;
  },
};
