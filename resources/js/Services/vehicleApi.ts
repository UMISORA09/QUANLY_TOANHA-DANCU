// Vehicle Management API Service - Module #5: Đăng Ký Phương Tiện & Tự Động Đẩy Phí Sang Hóa Đơn

export interface VehicleItem {
  id: string;
  apartment_id: string;
  owner_user_id: string;
  license_plate: string;
  vehicle_category: 'MOTORBIKE' | 'CAR' | 'E_SCOOTER' | 'BICYCLE';
  brand?: string | null;
  model?: string | null;
  color?: string | null;
  registration_certificate_number?: string | null;
  vehicle_photo_url?: string | null;
  registration_cert_photo_url?: string | null;
  monthly_parking_fee: number;
  has_electric_charging_subscription: boolean;
  is_active: boolean;
  approved_by?: string | null;
  approved_at?: string | null;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
  apartment?: {
    id: string;
    apartment_number: string;
    status?: string;
  };
  owner?: {
    id: string;
    full_name: string;
    email?: string;
    phone_number?: string;
  };
  approver?: {
    id: string;
    full_name: string;
  };
}

export interface VehicleInvoiceHistoryItem {
  item_id: string;
  invoice_id: string;
  invoice_number: string;
  billing_period: string;
  invoice_status: string;
  service_code: string;
  item_description: string;
  amount_before_tax: number;
  vat_amount: number;
  total_line_amount: number;
  created_at: string;
}

export interface VehicleDetailResponse {
  vehicle: VehicleItem;
  invoice_history: VehicleInvoiceHistoryItem[];
}

export interface VehiclePricingConfig {
  service_code: string;
  service_name: string;
  monthly_parking_fee: number;
  vat_percentage: number;
  unit_name: string;
}

export interface VehicleApartmentOption {
  id: string;
  apartment_number: string;
}

export interface VehicleResidentOption {
  user_id: string;
  full_name: string;
  phone_number?: string;
  email?: string;
  resident_type: string;
  is_head_of_household: boolean;
  apartment_id?: string;
  apartment_number?: string;
}

class VehicleApiService {
  private getBaseUrl(): string {
    return window.location.port === '5173'
      ? 'http://127.0.0.1:8000/api/v1'
      : '/api/v1';
  }

  private getAuthHeaders(): HeadersInit {
    let bearerToken = localStorage.getItem('smart_cassavas_token') || '';

    // If token is missing, check smartcassavas_session
    if (!bearerToken) {
      const sessionStr = localStorage.getItem('smartcassavas_session');
      if (sessionStr) {
        try {
          const session = JSON.parse(sessionStr);
          if (session.token || session.access_token) {
            bearerToken = session.token || session.access_token;
          } else if (session.role === 'admin' || session.isDev) {
            bearerToken = 'smart_token_admin_demo';
          } else if (session.role === 'receptionist' || session.role === 'security') {
            bearerToken = 'smart_token_reception_demo';
          } else {
            bearerToken = 'smart_token_manager_demo';
          }
        } catch {
          // ignore
        }
      }
    }

    // Default fallback demo token for manager/reception/admin session if needed
    if (!bearerToken) {
      const isReceptionPath =
        window.location.pathname.startsWith('/le-tan') ||
        window.location.pathname.startsWith('/an-ninh') ||
        window.location.pathname.startsWith('/bao-ve') ||
        window.location.pathname.startsWith('/reception');
      bearerToken = isReceptionPath ? 'smart_token_reception_demo' : 'smart_token_manager_demo';
    }

    // Save token if not yet saved so other operations can reuse it
    try {
      if (!localStorage.getItem('smart_cassavas_token')) {
        localStorage.setItem('smart_cassavas_token', bearerToken);
      }
    } catch {
      // ignore
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

    const resJson = await response.json();

    if (!response.ok) {
      const errorMsg =
        resJson.message ||
        resJson.error ||
        (resJson.errors ? Object.values(resJson.errors).flat().join(', ') : 'Đã có lỗi xảy ra.');
      throw new Error(errorMsg);
    }

    return resJson;
  }

  /**
   * Lấy danh sách phương tiện
   */
  async getVehicles(
    params: Record<string, any> = {},
    options: RequestInit = {}
  ): Promise<{
    success: boolean;
    data: VehicleItem[];
    meta: {
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
    };
  }> {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, val]) => {
      if (val !== undefined && val !== null && val !== '') {
        query.append(key, String(val));
      }
    });

    const endpoint = `/vehicles${query.toString() ? `?${query.toString()}` : ''}`;
    return this.request(endpoint, options);
  }

  /**
   * Lấy chi tiết phương tiện và lịch sử hóa đơn
   */
  async getVehicleById(id: string): Promise<{
    success: boolean;
    data: VehicleDetailResponse;
  }> {
    return this.request(`/vehicles/${id}`);
  }

  /**
   * Đăng ký phương tiện mới
   */
  async createVehicle(payload: {
    apartment_id: string;
    owner_user_id: string;
    license_plate: string;
    vehicle_category: string;
    brand?: string;
    model?: string;
    color?: string;
    registration_certificate_number?: string;
    vehicle_photo_url?: string;
    registration_cert_photo_url?: string;
    monthly_parking_fee?: number;
    has_electric_charging_subscription?: boolean;
    is_active?: boolean;
  }): Promise<{
    success: boolean;
    message: string;
    data: VehicleItem;
    invoice_sync?: any;
  }> {
    return this.request('/vehicles', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  /**
   * Cập nhật thông tin phương tiện
   */
  async updateVehicle(
    id: string,
    payload: Partial<{
      apartment_id: string;
      owner_user_id: string;
      license_plate: string;
      vehicle_category: string;
      brand?: string;
      model?: string;
      color?: string;
      registration_certificate_number?: string;
      vehicle_photo_url?: string;
      registration_cert_photo_url?: string;
      monthly_parking_fee?: number;
      has_electric_charging_subscription?: boolean;
      is_active?: boolean;
    }>
  ): Promise<{
    success: boolean;
    message: string;
    data: VehicleItem;
  }> {
    return this.request(`/vehicles/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  /**
   * Xóa mềm phương tiện
   */
  async deleteVehicle(id: string): Promise<{
    success: boolean;
    message: string;
  }> {
    return this.request(`/vehicles/${id}`, {
      method: 'DELETE',
    });
  }

  /**
   * Bật / tắt trạng thái kích hoạt
   */
  async toggleActive(id: string): Promise<{
    success: boolean;
    message: string;
    data: VehicleItem;
  }> {
    return this.request(`/vehicles/${id}/toggle-active`, {
      method: 'PATCH',
    });
  }

  /**
   * Phê duyệt phương tiện
   */
  async approveVehicle(id: string): Promise<{
    success: boolean;
    message: string;
    data: VehicleItem;
  }> {
    return this.request(`/vehicles/${id}/approve`, {
      method: 'POST',
    });
  }

  /**
   * Lấy cấu hình đơn giá giữ xe
   */
  async getPricingConfigs(category?: string): Promise<{
    success: boolean;
    data: Record<string, VehiclePricingConfig> | VehiclePricingConfig;
  }> {
    const endpoint = category
      ? `/vehicles/pricing-config?category=${encodeURIComponent(category)}`
      : '/vehicles/pricing-config';
    return this.request(endpoint);
  }

  /**
   * Đẩy / đồng bộ phí gửi xe vào hóa đơn
   */
  async syncInvoice(id: string, billingPeriod?: string): Promise<{
    success: boolean;
    message: string;
    data: any;
  }> {
    return this.request(`/vehicles/${id}/sync-invoice`, {
      method: 'POST',
      body: JSON.stringify({ billing_period: billingPeriod }),
    });
  }

  /**
   * Lấy danh sách căn hộ cho bộ lọc và form
   */
  async getApartments(): Promise<{
    success: boolean;
    data: VehicleApartmentOption[];
  }> {
    return this.request('/vehicles/meta/apartments');
  }

  /**
   * Lấy danh sách cư dân theo căn hộ
   */
  async getApartmentResidents(apartmentId: string): Promise<{
    success: boolean;
    data: VehicleResidentOption[];
  }> {
    return this.request(`/vehicles/meta/apartments/${apartmentId}/residents`);
  }

  /**
   * Lấy danh sách cư dân tòa nhà hỗ trợ tìm kiếm và chọn chủ xe linh hoạt (phân trang / giới hạn)
   */
  async getAllResidents(
    params?: string | { search?: string; apartment_id?: string; limit?: number },
    options: RequestInit = {}
  ): Promise<{
    success: boolean;
    data: (VehicleResidentOption & { apartment_id?: string; apartment_number?: string })[];
  }> {
    const query = new URLSearchParams();
    if (typeof params === 'string') {
      if (params) query.append('apartment_id', params);
    } else if (params && typeof params === 'object') {
      if (params.apartment_id) query.append('apartment_id', params.apartment_id);
      if (params.search) query.append('search', params.search);
      if (params.limit) query.append('limit', String(params.limit));
    }

    const endpoint = `/vehicles/meta/residents${query.toString() ? `?${query.toString()}` : ''}`;
    return this.request(endpoint, options);
  }
}

export const vehicleApi = new VehicleApiService();
export default vehicleApi;
