/**
 * Dịch vụ API Quản lý Đồng hồ & Chốt chỉ số Điện/Nước
 * Tương thích tiêu chuẩn RESTful của hệ thống quản lý tòa nhà
 */

export interface MeterModel {
    id: string;
    meter_code: string;
    meter_type: 'ELECTRICITY' | 'WATER' | 'COLD_WATER';
    apartment_id: string;
    installation_date: string;
    initial_reading: number;
    current_reading: number;
    last_reading_date: string | null;
    multiplier_factor: number;
    calibration_due_date: string | null;
    is_active: boolean;
    notes: string | null;
    apartment?: {
        id: string;
        apartment_number: string;
        block_id: string;
        floor_id: string;
        block?: {
            id: string;
            block_code: string;
            block_name: string;
        };
        floor?: {
            id: string;
            floor_number: number;
            floor_name: string;
        };
    };
    readings?: MeterReadingModel[];
}

export interface MeterReadingModel {
    id: string;
    meter_id: string;
    apartment_id: string;
    batch_id: string | null;
    billing_cycle: string;
    period_start_date: string;
    period_end_date: string;
    previous_reading: number;
    current_reading: number;
    consumed_units: number;
    reading_source: string;
    recorded_by_user_id: string | null;
    meter_photo_url: string | null;
    ai_detected_reading: number | null;
    ai_confidence_score: number | null;
    is_abnormal_consumption: boolean;
    abnormal_reason: string | null;
    is_locked_for_billing: boolean;
    created_at: string;
    meter?: MeterModel;
    apartment?: {
        id: string;
        apartment_number: string;
        block?: {
            id: string;
            block_name: string;
        };
        floor?: {
            id: string;
            floor_name: string;
        };
    };
    recorded_by?: {
        id: string;
        full_name: string;
        username: string;
    };
}

export interface MeterSummaryData {
    billing_cycle: string;
    total_meters: number;
    recorded_meters: number;
    pending_meters: number;
    completion_rate: number;
    abnormal_count: number;
    total_electricity_kwh: number;
    total_water_m3: number;
    is_cycle_locked: boolean;
}

export interface PaginatedResponse<T> {
    success: boolean;
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

function getAuthHeaders(isFormData = false): Record<string, string> {
    const token =
        localStorage.getItem('auth_token') ||
        localStorage.getItem('token') ||
        localStorage.getItem('access_token') ||
        '';

    const csrfToken =
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const headers: Record<string, string> = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
    }

    if (!isFormData) {
        headers['Content-Type'] = 'application/json';
    }

    return headers;
}

export const meterReadingApi = {
    /**
     * Lấy tổng quan thống kê tiến độ chốt số theo kỳ
     */
    async getSummary(cycle: string, blockId?: string): Promise<MeterSummaryData> {
        const params = new URLSearchParams({ cycle });
        if (blockId) params.append('block_id', blockId);

        const res = await fetch(`/api/v1/meter-readings/summary?${params.toString()}`, {
            headers: getAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Không thể lấy dữ liệu thống kê');
        }
        return json.data;
    },

    /**
     * Danh sách đồng hồ kèm chỉ số ghi nhận trong kỳ
     */
    async getMeters(params: {
        cycle?: string;
        search?: string;
        meter_type?: string;
        block_id?: string;
        floor_id?: string;
        apartment_id?: string;
        recorded_status?: 'recorded' | 'pending';
        page?: number;
        per_page?: number;
    }): Promise<PaginatedResponse<MeterModel>> {
        const query = new URLSearchParams();
        if (params.cycle) query.append('cycle', params.cycle);
        if (params.search) query.append('search', params.search);
        if (params.meter_type) query.append('meter_type', params.meter_type);
        if (params.block_id) query.append('block_id', params.block_id);
        if (params.floor_id) query.append('floor_id', params.floor_id);
        if (params.apartment_id) query.append('apartment_id', params.apartment_id);
        if (params.recorded_status) query.append('recorded_status', params.recorded_status);
        if (params.page) query.append('page', params.page.toString());
        if (params.per_page) query.append('per_page', params.per_page.toString());

        const res = await fetch(`/api/v1/meters?${query.toString()}`, {
            headers: getAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Không thể tải danh sách đồng hồ');
        }
        return json;
    },

    /**
     * Khai báo đồng hồ mới cho căn hộ
     */
    async createMeter(payload: {
        apartment_id: string;
        meter_type: string;
        meter_code?: string;
        installation_date?: string;
        initial_reading?: number;
        multiplier_factor?: number;
        notes?: string;
    }): Promise<MeterModel> {
        const res = await fetch('/api/v1/meters', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Không thể khai báo đồng hồ');
        }
        return json.data;
    },

    /**
     * Danh sách các bản ghi chỉ số đã chốt
     */
    async getReadings(params: {
        cycle?: string;
        meter_type?: string;
        block_id?: string;
        apartment_id?: string;
        search?: string;
        is_abnormal?: boolean;
        page?: number;
        per_page?: number;
    }): Promise<PaginatedResponse<MeterReadingModel>> {
        const query = new URLSearchParams();
        if (params.cycle) query.append('cycle', params.cycle);
        if (params.meter_type) query.append('meter_type', params.meter_type);
        if (params.block_id) query.append('block_id', params.block_id);
        if (params.apartment_id) query.append('apartment_id', params.apartment_id);
        if (params.search) query.append('search', params.search);
        if (params.is_abnormal !== undefined) query.append('is_abnormal', params.is_abnormal ? '1' : '0');
        if (params.page) query.append('page', params.page.toString());
        if (params.per_page) query.append('per_page', params.per_page.toString());

        const res = await fetch(`/api/v1/meter-readings?${query.toString()}`, {
            headers: getAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Không thể tải danh sách chỉ số');
        }
        return json;
    },

    /**
     * Chốt chỉ số đo thủ công qua form
     */
    async recordReading(payload: FormData | Record<string, any>): Promise<MeterReadingModel> {
        const isFormData = payload instanceof FormData;
        const res = await fetch('/api/v1/meter-readings', {
            method: 'POST',
            headers: getAuthHeaders(isFormData),
            body: isFormData ? payload : JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Lỗi khi lưu chỉ số đo');
        }
        return json.data;
    },

    /**
     * Cập nhật bản ghi chỉ số đo
     */
    async updateReading(id: string, payload: FormData | Record<string, any>): Promise<MeterReadingModel> {
        const isFormData = payload instanceof FormData;
        const url = `/api/v1/meter-readings/${id}`;
        
        let res: Response;
        if (isFormData) {
            payload.append('_method', 'PUT');
            res = await fetch(url, {
                method: 'POST',
                headers: getAuthHeaders(true),
                body: payload,
            });
        } else {
            res = await fetch(url, {
                method: 'PUT',
                headers: getAuthHeaders(false),
                body: JSON.stringify(payload),
            });
        }

        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Lỗi khi cập nhật chỉ số');
        }
        return json.data;
    },

    /**
     * Xóa bản ghi chỉ số đo
     */
    async deleteReading(id: string): Promise<void> {
        const res = await fetch(`/api/v1/meter-readings/${id}`, {
            method: 'DELETE',
            headers: getAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Lỗi khi xóa bản ghi');
        }
    },

    /**
     * Khóa sổ kỳ chốt số
     */
    async lockCycle(cycle: string, blockId?: string): Promise<{ locked_count: number; message: string }> {
        const res = await fetch('/api/v1/meter-readings/lock-cycle', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({
                billing_cycle: cycle,
                block_id: blockId || null,
            }),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Lỗi khi khóa sổ kỳ');
        }
        return json;
    },

    /**
     * Mở khóa sổ kỳ chốt số
     */
    async unlockCycle(cycle: string, blockId?: string): Promise<{ unlocked_count: number; message: string }> {
        const res = await fetch('/api/v1/meter-readings/unlock-cycle', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({
                billing_cycle: cycle,
                block_id: blockId || null,
            }),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Lỗi khi mở khóa sổ kỳ');
        }
        return json;
    },
};
