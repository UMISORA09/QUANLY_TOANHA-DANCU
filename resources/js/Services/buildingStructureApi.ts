// Building & Floor & Apartment Management API Service
// Chức năng #2: Quản lý Tầng & Căn hộ (Block -> Floor -> Apartment, Batch Generate, Trạng thái: Đã bán/Đang ở, Đang thuê, Trống, Đang bảo trì)

export interface BlockItem {
  id: string;
  block_code: string;
  block_name: string;
  total_floors: number;
  total_basements: number;
  total_apartments: number;
  occupied_apartments: number;
  vacant_apartments: number;
  rented_apartments: number;
  maintenance_apartments: number;
  building_manager_name?: string | null;
  building_manager_phone?: string | null;
  fire_safety_status?: 'safe' | 'warning' | string;
  power_status?: 'stable' | 'alert' | string;
  water_status?: 'stable' | 'alert' | string;
  floors_count?: number;
  apartments_count?: number;
}

export interface FloorItem {
  id: string;
  block_id: string;
  floor_number: number;
  floor_code: string;
  floor_name: string;
  floor_type: 'RESIDENTIAL' | 'COMMERCIAL' | 'PARKING' | 'TECHNICAL' | 'AMENITY' | string;
  total_units: number;
  apartments_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface ApartmentItem {
  id: string;
  block_id: string;
  floor_id: string;
  apartment_number: string;
  room_type: string;
  gross_floor_area_sqm: number;
  net_usable_area_sqm?: number | null;
  bedroom_count: number;
  bathroom_count: number;
  water_quota_registered: number;
  status: 'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE';
  monthly_management_fee_fixed?: number | null;
  furnished_status?: 'RAW' | 'BASIC' | 'FULL' | string | null;
  notes?: string | null;
  created_at?: string;
  updated_at?: string;
  block?: {
    id: string;
    block_code: string;
    block_name: string;
  };
  floor?: {
    id: string;
    floor_number: number;
    floor_code: string;
    floor_name: string;
  };
  primary_owner?: {
    id: string;
    full_name: string;
    phone_number?: string | null;
    email?: string | null;
  } | null;
}

export interface ApartmentQueryParams {
  block_id?: string;
  floor_id?: string;
  status?: string;
  room_type?: string;
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
}

export interface BatchGeneratePayload {
  block_id: string;
  floor_id: string;
  count: number;
  prefix: string;
  start_number?: number;
  room_type: string;
  gross_floor_area_sqm: number;
  net_usable_area_sqm?: number;
  bedroom_count?: number;
  bathroom_count?: number;
  water_quota_registered?: number;
  status: 'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE';
  furnished_status?: 'RAW' | 'BASIC' | 'FULL';
  monthly_management_fee_fixed?: number;
}

export interface ApiResponse<T> {
  success: boolean;
  message?: string;
  data: T;
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number;
    to?: number;
  };
}

export interface BuildingBootstrapData {
  blocks: BlockItem[];
  floors: FloorItem[];
  stats: {
    total_apartments: number;
    vacant: number;
    occupied: number;
    rented: number;
    maintenance: number;
    occupancy_rate: number;
  };
  apartments: ApartmentItem[];
  version: number;
}

class BuildingStructureApiService {
  private getBaseUrl(): string {
    return '';
  }

  private getAuthHeaders(): HeadersInit {
    let bearerToken = '';

    try {
      const stored =
        localStorage.getItem('smart_cassavas_token') ||
        localStorage.getItem('access_token') ||
        localStorage.getItem('token') ||
        localStorage.getItem('auth_token');
      if (stored) {
        bearerToken = stored;
      }
    } catch {
      // ignore
    }

    if (!bearerToken) {
      const adminAuth = localStorage.getItem('admin_auth_user');
      const userSession = localStorage.getItem('smart_user_session');

      if (adminAuth || userSession) {
        try {
          const session = JSON.parse(adminAuth || userSession || '{}');
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
      bearerToken = 'smart_token_manager_demo';
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

    const responseData = await response.json().catch(() => ({}));

    if (!response.ok) {
      const msg = responseData.message || `Lỗi yêu cầu: ${response.status} ${response.statusText}`;
      throw new Error(msg);
    }

    return responseData as T;
  }

  // --- BOOTSTRAP: Load all blocks, floors, stats & apartments in 1 single fast roundtrip ---
  async getBootstrap(): Promise<BuildingBootstrapData> {
    const res = await this.request<{ success: boolean; data: BuildingBootstrapData }>('/api/v1/buildings/bootstrap');
    return res.data;
  }

  // --- VERSION / SYNC HEARTBEAT ---
  async getDataVersion(): Promise<{ version: number; timestamp: number }> {
    try {
      const res = await this.request<{ success: boolean; version: number; timestamp: number }>('/api/v1/buildings/version');
      return { version: res.version || 1, timestamp: res.timestamp || Date.now() };
    } catch {
      return { version: 1, timestamp: Date.now() };
    }
  }

  // --- BLOCKS ---
  async getBlocks(): Promise<BlockItem[]> {
    const res = await this.request<ApiResponse<BlockItem[]>>('/api/v1/blocks');
    return res.data;
  }

  // --- FLOORS ---
  async getFloors(blockId: string): Promise<FloorItem[]> {
    const res = await this.request<ApiResponse<FloorItem[]>>(`/api/v1/blocks/${blockId}/floors`);
    return res.data;
  }

  async createFloor(blockId: string, data: Partial<FloorItem>): Promise<FloorItem> {
    const res = await this.request<ApiResponse<FloorItem>>(`/api/v1/blocks/${blockId}/floors`, {
      method: 'POST',
      body: JSON.stringify(data),
    });
    return res.data;
  }

  // --- APARTMENTS ---
  async getApartments(params: ApartmentQueryParams = {}): Promise<ApiResponse<ApartmentItem[]>> {
    const query = new URLSearchParams();
    if (params.block_id && params.block_id !== 'all') query.set('block_id', params.block_id);
    if (params.floor_id && params.floor_id !== 'all') query.set('floor_id', params.floor_id);
    if (params.status && params.status !== 'all') query.set('status', params.status);
    if (params.room_type && params.room_type !== 'all') query.set('room_type', params.room_type);
    if (params.search) query.set('search', params.search);
    if (params.page) query.set('page', params.page.toString());
    if (params.per_page) query.set('per_page', params.per_page.toString());
    if (params.sort_by) query.set('sort_by', params.sort_by);
    if (params.sort_order) query.set('sort_order', params.sort_order);

    const queryString = query.toString();
    const endpoint = `/api/v1/apartments${queryString ? `?${queryString}` : ''}`;
    return await this.request<ApiResponse<ApartmentItem[]>>(endpoint);
  }

  async getApartment(id: string): Promise<ApartmentItem> {
    const res = await this.request<ApiResponse<ApartmentItem>>(`/api/v1/apartments/${id}`);
    return res.data;
  }

  async createApartment(data: Partial<ApartmentItem>): Promise<ApartmentItem> {
    const res = await this.request<ApiResponse<ApartmentItem>>('/api/v1/apartments', {
      method: 'POST',
      body: JSON.stringify(data),
    });
    return res.data;
  }

  async updateApartment(id: string, data: Partial<ApartmentItem>): Promise<ApartmentItem> {
    const res = await this.request<ApiResponse<ApartmentItem>>(`/api/v1/apartments/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
    return res.data;
  }

  async updateApartmentStatus(id: string, status: 'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE', note?: string): Promise<ApartmentItem> {
    const res = await this.request<ApiResponse<ApartmentItem>>(`/api/v1/apartments/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status, note }),
    });
    return res.data;
  }

  async batchGenerateApartments(payload: BatchGeneratePayload): Promise<{ created_count: number; skipped_existing: number; apartments: ApartmentItem[] }> {
    const res = await this.request<ApiResponse<{ created_count: number; skipped_existing: number; apartments: ApartmentItem[] }>>('/api/v1/apartments/batch-generate', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    return res.data;
  }

  async deleteApartment(id: string): Promise<boolean> {
    const res = await this.request<ApiResponse<null>>(`/api/v1/apartments/${id}`, {
      method: 'DELETE',
    });
    return res.success;
  }
}

export const buildingStructureApi = new BuildingStructureApiService();
export default buildingStructureApi;
