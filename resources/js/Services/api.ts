// Real HTTP REST API Client for Smart Apartment Management

const API_BASE_URL = '/api/v1';

export interface Category {
  id: string;
  category_name: string;
  category_code: string;
  icon_name?: string | null;
  description?: string | null;
  created_at: string;
  amenities_count: number;
}

export interface Amenity {
  id: string;
  category_id: string;
  block_id?: string | null;
  amenity_name: string;
  amenity_code: string;
  location_detail: string;
  max_capacity_per_slot: number;
  hourly_rate: number | string;
  security_deposit_required: number | string;
  advance_booking_days_limit: number;
  min_cancel_hours_before: number;
  requires_admin_approval: boolean;
  rules_and_regulations?: string | null;
  cover_image_url?: string | null;
  gallery_images?: string[];
  is_active: boolean;
  version: number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
  category_name?: string | null;
  category_code?: string | null;
  block_name?: string | null;
  block_code?: string | null;
  time_slots_count: number;
  active_time_slots_count: number;
  max_bookings_per_slot: number;
  active_bookings_count?: number;
}

export interface AmenityBooking {
  id: string;
  booking_code: string;
  amenity_id: string;
  apartment_id: string;
  resident_user_id: string;
  resident_name: string;
  resident_phone?: string;
  apartment_number?: string;
  block_name?: string;
  booking_date: string;
  start_time: string;
  end_time: string;
  attendee_count: number;
  total_amount: number;
  deposit_amount: number;
  is_paid: boolean;
  status: string;
  checkin_qr_code: string;
  checked_in_at?: string | null;
  resident_notes?: string | null;
  admin_notes?: string | null;
  created_at: string;
}

export interface AmenityListResponse {
  items: Amenity[];
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}

export interface TimeSlot {
  id: string;
  amenity_id: string;
  day_of_week: number;
  slot_start_time: string;
  slot_end_time: string;
  slot_label?: string | null;
  max_bookings: number;
  is_active: boolean;
  created_at: string;
}

export interface Blackout {
  id: string;
  amenity_id: string;
  blackout_date: string;
  start_time?: string | null;
  end_time?: string | null;
  reason: string;
  created_by?: string | null;
  created_at: string;
}

export interface BlockOption {
  id: string;
  block_code: string;
  block_name: string;
}

class ApiService {
  private getToken(): string | null {
    return localStorage.getItem('smart_cassavas_token');
  }

  public setToken(token: string) {
    localStorage.setItem('smart_cassavas_token', token);
  }

  public removeToken() {
    localStorage.removeItem('smart_cassavas_token');
  }

  public getUser(): any | null {
    try {
      const u = localStorage.getItem('smart_cassavas_user');
      return u ? JSON.parse(u) : null;
    } catch {
      return null;
    }
  }

  public setUser(user: any) {
    if (!user) {
      localStorage.removeItem('smart_cassavas_user');
    } else {
      localStorage.setItem('smart_cassavas_user', JSON.stringify(user));
    }
  }

  public logout() {
    this.removeToken();
    localStorage.removeItem('smart_cassavas_user');
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const url = `${API_BASE_URL}${endpoint}`;
    const token = this.getToken();

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers as Record<string, string> || {}),
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(url, {
      ...options,
      headers,
    });

    if (response.status === 204) {
      return {} as T;
    }

    const data = await response.json().catch(() => null);

    if (!response.ok) {
      const msg = data?.detail || data?.message || `Yêu cầu thất bại (${response.status})`;
      throw new Error(typeof msg === 'string' ? msg : JSON.stringify(msg));
    }

    return data as T;
  }

  // ================= AUTH =================
  async login(identifier: string, password: string) {
    const res = await this.request<{
      access_token: string;
      token_type: string;
      user: any;
    }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ identifier, password }),
    });
    if (res.access_token) {
      this.setToken(res.access_token);
    }
    if (res.user) {
      this.setUser(res.user);
    }
    return res;
  }

  async getMe() {
    return this.request<any>('/auth/me');
  }

  // ================= CATEGORIES =================
  async getCategories(): Promise<Category[]> {
    return this.request<Category[]>('/admin/amenity-categories');
  }

  async createCategory(payload: {
    category_name: string;
    category_code: string;
    icon_name?: string | null;
    description?: string | null;
  }): Promise<Category> {
    return this.request<Category>('/admin/amenity-categories', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateCategory(
    id: string,
    payload: {
      category_name: string;
      category_code: string;
      icon_name?: string | null;
      description?: string | null;
    }
  ): Promise<Category> {
    return this.request<Category>(`/admin/amenity-categories/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async deleteCategory(id: string): Promise<void> {
    return this.request<void>(`/admin/amenity-categories/${id}`, {
      method: 'DELETE',
    });
  }

  // ================= AMENITIES =================
  async getAmenities(params: {
    search?: string;
    category_id?: string;
    block_id?: string;
    is_active?: boolean;
    page?: number;
    limit?: number;
    sort?: string;
  } = {}): Promise<AmenityListResponse> {
    const query = new URLSearchParams();
    if (params.search) query.set('search', params.search);
    if (params.category_id) query.set('category_id', params.category_id);
    if (params.block_id) query.set('block_id', params.block_id);
    if (params.is_active !== undefined) query.set('is_active', String(params.is_active));
    if (params.page) query.set('page', String(params.page));
    if (params.limit) query.set('limit', String(params.limit));
    if (params.sort) query.set('sort', params.sort);

    return this.request<AmenityListResponse>(`/admin/amenities?${query.toString()}`);
  }

  async getAmenity(id: string): Promise<Amenity> {
    return this.request<Amenity>(`/admin/amenities/${id}`);
  }

  async createAmenity(payload: Partial<Amenity>): Promise<Amenity> {
    return this.request<Amenity>('/admin/amenities', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateAmenity(id: string, payload: Partial<Amenity>): Promise<Amenity> {
    return this.request<Amenity>(`/admin/amenities/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async patchAmenityStatus(id: string, is_active: boolean): Promise<Amenity> {
    return this.request<Amenity>(`/admin/amenities/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active }),
    });
  }

  async deleteAmenity(id: string): Promise<void> {
    return this.request<void>(`/admin/amenities/${id}`, {
      method: 'DELETE',
    });
  }

  // ================= TIME SLOTS =================
  async getTimeSlots(amenityId: string): Promise<TimeSlot[]> {
    return this.request<TimeSlot[]>(`/admin/amenities/${amenityId}/time-slots`);
  }

  async createTimeSlot(
    amenityId: string,
    payload: {
      day_of_week: number;
      slot_start_time: string;
      slot_end_time: string;
      slot_label?: string | null;
      max_bookings: number;
      is_active?: boolean;
    }
  ): Promise<TimeSlot> {
    return this.request<TimeSlot>(`/admin/amenities/${amenityId}/time-slots`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateTimeSlot(
    amenityId: string,
    slotId: string,
    payload: {
      day_of_week: number;
      slot_start_time: string;
      slot_end_time: string;
      slot_label?: string | null;
      max_bookings: number;
      is_active?: boolean;
    }
  ): Promise<TimeSlot> {
    return this.request<TimeSlot>(`/admin/amenities/${amenityId}/time-slots/${slotId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async patchTimeSlotStatus(amenityId: string, slotId: string, is_active: boolean): Promise<TimeSlot> {
    return this.request<TimeSlot>(`/admin/amenities/${amenityId}/time-slots/${slotId}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active }),
    });
  }

  async deleteTimeSlot(amenityId: string, slotId: string): Promise<void> {
    return this.request<void>(`/admin/amenities/${amenityId}/time-slots/${slotId}`, {
      method: 'DELETE',
    });
  }

  // ================= BLACKOUTS =================
  async getBlackouts(amenityId: string): Promise<Blackout[]> {
    return this.request<Blackout[]>(`/admin/amenities/${amenityId}/blackouts`);
  }

  async createBlackout(
    amenityId: string,
    payload: {
      blackout_date: string;
      start_time?: string | null;
      end_time?: string | null;
      reason: string;
    }
  ): Promise<Blackout> {
    return this.request<Blackout>(`/admin/amenities/${amenityId}/blackouts`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateBlackout(
    amenityId: string,
    blackoutId: string,
    payload: {
      blackout_date: string;
      start_time?: string | null;
      end_time?: string | null;
      reason: string;
    }
  ): Promise<Blackout> {
    return this.request<Blackout>(`/admin/amenities/${amenityId}/blackouts/${blackoutId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async deleteBlackout(amenityId: string, blackoutId: string): Promise<void> {
    return this.request<void>(`/admin/amenities/${amenityId}/blackouts/${blackoutId}`, {
      method: 'DELETE',
    });
  }

  // ================= BOOKINGS =================
  async getAmenityBookings(amenityId: string): Promise<AmenityBooking[]> {
    return this.request<AmenityBooking[]>(`/admin/amenities/${amenityId}/bookings`);
  }

  // ================= META =================
  async getBlocks(): Promise<BlockOption[]> {
    try {
      return await this.request<BlockOption[]>('/admin/blocks');
    } catch {
      return await this.request<BlockOption[]>('/meta/blocks');
    }
  }
}

export const api = new ApiService();
export default api;
