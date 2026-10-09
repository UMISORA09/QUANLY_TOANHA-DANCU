// RFID Management API Service - Quản Lý Mã Thẻ RFID / Cổng & Thang Máy (ACTIVE / LOCK)

export interface RfidCardItem {
  id: string;
  card_uid: string;
  card_number: string;
  card_type: 'RESIDENT_ALL_ACCESS' | 'PARKING_ONLY' | 'ELEVATOR_ONLY' | 'VISITOR_PASS';
  assigned_user_id?: string | null;
  assigned_apartment_id?: string | null;
  assigned_vehicle_id?: string | null;
  issued_date: string;
  expiry_date?: string | null;
  status: 'ACTIVE' | 'LOCKED' | 'LOCKED_TEMPORARY' | 'LOST_REPORTED' | 'REVOKED';
  deposit_fee: number;
  created_at: string;
  updated_at: string;
  assigned_user?: {
    id: string;
    full_name: string;
    email?: string;
    phone_number?: string;
  } | null;
  assigned_apartment?: {
    id: string;
    apartment_number: string;
    block_id?: string;
    status?: string;
  } | null;
  assigned_vehicle?: {
    id: string;
    license_plate: string;
    vehicle_category: string;
    model?: string;
  } | null;
}

export interface RfidAccessLogItem {
  id: string;
  access_direction: string;
  log_timestamp: string;
  created_at: string;
}

export interface RfidCardDetailResponse {
  card: RfidCardItem;
  recent_logs: RfidAccessLogItem[];
}

export interface RfidApartmentOption {
  id: string;
  apartment_number: string;
  block_id?: string;
}

export interface RfidResidentOption {
  user_id: string;
  full_name: string;
  phone_number?: string;
  apartment_id?: string;
}

export interface RfidListResponse {
  success: boolean;
  data: RfidCardItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

class RfidApiService {
  private getBaseUrl(): string {
    return window.location.port === '5173'
      ? 'http://127.0.0.1:8000/api/v1'
      : '/api/v1';
  }

  private getAuthHeaders(): HeadersInit {
    let bearerToken = localStorage.getItem('smart_cassavas_token') || '';

    if (!bearerToken) {
      const sessionStr = localStorage.getItem('smartcassavas_session');
      if (sessionStr) {
        try {
          const session = JSON.parse(sessionStr);
          if (session.token || session.access_token) {
            bearerToken = session.token || session.access_token;
          } else if (session.role === 'admin' || session.isDev) {
            bearerToken = 'smart_token_admin_demo';
          } else {
            bearerToken = 'smart_token_manager_demo';
          }
        } catch {
          // ignore
        }
      }
    }

    if (!bearerToken) {
      bearerToken = 'smart_token_admin_demo';
    }

    return {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: bearerToken.startsWith('Bearer ') ? bearerToken : `Bearer ${bearerToken}`,
    };
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const url = `${this.getBaseUrl()}${endpoint}`;
    const headers = {
      ...this.getAuthHeaders(),
      ...(options.headers || {}),
    };

    const response = await fetch(url, {
      ...options,
      headers,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      const error: any = new Error(data.message || `Request failed with status ${response.status}`);
      error.status = response.status;
      error.data = data;
      error.error = data.error;
      error.retry_after = data.retry_after;
      throw error;
    }

    return data;
  }

  /**
   * Lấy danh sách thẻ RFID phân trang và tìm kiếm
   */
  public async listCards(params: {
    page?: number;
    per_page?: number;
    search?: string;
    status?: string;
    card_type?: string;
    apartment_id?: string;
    sort_by?: string;
    sort_direction?: string;
  } = {}): Promise<RfidListResponse> {
    const query = new URLSearchParams();
    if (params.page) query.append('page', params.page.toString());
    if (params.per_page) query.append('per_page', params.per_page.toString());
    if (params.search) query.append('search', params.search);
    if (params.status && params.status !== 'ALL') query.append('status', params.status);
    if (params.card_type && params.card_type !== 'ALL') query.append('card_type', params.card_type);
    if (params.apartment_id) query.append('apartment_id', params.apartment_id);
    if (params.sort_by) query.append('sort_by', params.sort_by);
    if (params.sort_direction) query.append('sort_direction', params.sort_direction);

    const qs = query.toString();
    return this.request<RfidListResponse>(`/rfid-cards${qs ? `?${qs}` : ''}`);
  }

  /**
   * Lấy chi tiết thẻ RFID kèm lịch sử quét
   */
  public async getCard(id: string): Promise<{ success: boolean; data: RfidCardDetailResponse }> {
    return this.request<{ success: boolean; data: RfidCardDetailResponse }>(`/rfid-cards/${id}`);
  }

  /**
   * Cấp mới thẻ RFID
   */
  public async createCard(payload: {
    card_uid: string;
    card_number: string;
    card_type?: string;
    assigned_user_id?: string | null;
    assigned_apartment_id?: string | null;
    assigned_vehicle_id?: string | null;
    issued_date?: string;
    expiry_date?: string | null;
    status?: string;
    deposit_fee?: number;
  }): Promise<{ success: boolean; message: string; data: RfidCardItem }> {
    return this.request<{ success: boolean; message: string; data: RfidCardItem }>('/rfid-cards', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  /**
   * Cập nhật thông tin thẻ RFID
   */
  public async updateCard(id: string, payload: Partial<RfidCardItem>): Promise<{ success: boolean; message: string; data: RfidCardItem }> {
    return this.request<{ success: boolean; message: string; data: RfidCardItem }>(`/rfid-cards/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  /**
   * Chuyển đổi trạng thái ACTIVE <-> LOCKED_TEMPORARY
   */
  public async toggleStatus(id: string): Promise<{ success: boolean; message: string; data: RfidCardItem }> {
    return this.request<{ success: boolean; message: string; data: RfidCardItem }>(`/rfid-cards/${id}/toggle-status`, {
      method: 'PATCH',
    });
  }

  /**
   * Xóa / vô hiệu hóa thẻ
   */
  public async deleteCard(id: string): Promise<{ success: boolean; message: string; action: string }> {
    return this.request<{ success: boolean; message: string; action: string }>(`/rfid-cards/${id}`, {
      method: 'DELETE',
    });
  }

  /**
   * Danh mục căn hộ cho dropdown
   */
  public async getApartments(): Promise<{ success: boolean; data: RfidApartmentOption[] }> {
    return this.request<{ success: boolean; data: RfidApartmentOption[] }>('/rfid-cards/meta/apartments');
  }

  /**
   * Danh mục cư dân cho dropdown
   */
  public async getResidents(apartmentId?: string): Promise<{ success: boolean; data: RfidResidentOption[] }> {
    const qs = apartmentId ? `?apartment_id=${encodeURIComponent(apartmentId)}` : '';
    return this.request<{ success: boolean; data: RfidResidentOption[] }>(`/rfid-cards/meta/residents${qs}`);
  }
}

export const rfidApi = new RfidApiService();
export default rfidApi;
