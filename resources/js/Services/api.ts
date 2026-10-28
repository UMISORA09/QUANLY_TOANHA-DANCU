// Real HTTP REST API Client for Smart Apartment Management with SWR & ETag Support

import { amenityCache } from './amenityCache';

const API_BASE_URL = window.location.port === '5173'
  ? 'http://127.0.0.1:8000/api/v1'
  : '/api/v1';

// Client-side LRU Cache for Instant Typeahead Suggestions (0ms response time)
const suggestionCache = new Map<string, { data: Array<{ id: string; label: string; code?: string; type?: string; category?: string }>; ts: number }>();
const MAX_SUGGESTION_CACHE = 100;
const SUGGESTION_TTL = 60 * 1000; // 60s

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
  rejection_reason?: string | null;
  approved_at?: string | null;
  approved_by_user_id?: string | null;
  resident_notes?: string | null;
  admin_notes?: string | null;
  created_at: string;
  payment?: AmenityBookingPayment | null;
}

export interface AmenityBookingPayment {
  id: string;
  booking_id: string;
  reference: string;
  amount: number;
  bank_name: string;
  account_number: string;
  account_name: string;
  status: 'WAITING_APPROVAL' | 'PENDING' | 'REPORTED' | 'PAID' | 'EXPIRED' | 'CANCELLED' | 'REVIEW';
  expires_at: string | null;
  qr_url: string | null;
  can_pay: boolean;
  checkout_available?: boolean;
  checkout_environment?: 'sandbox' | 'production';
  can_confirm: boolean;
  refund_required: boolean;
  bank_transaction_id: string | null;
  received_amount: number | null;
  received_at: string | null;
  confirmed_at: string | null;
  review_reason: string | null;
  review_due_at?: string | null;
  review_overdue?: boolean;
}

export interface AmenityBookingNotification {
  id: string;
  title: string;
  message: string;
  category: 'AMENITY_BOOKING' | 'AMENITY';
  timeAgo: string;
  isRead: boolean;
  deepLink: string;
}

export interface AmenityNotificationFeed {
  items: AmenityBookingNotification[];
  unread_count: number;
  page: number;
  total_pages: number;
  latest_unread?: AmenityBookingNotification | null;
}

export interface AmenityClosureImpact {
  count: number;
  received_amount: number;
  reported_count: number;
  confirmation_token: string;
  items: { booking_code: string; booking_date: string; start_time: string; end_time: string }[];
}

export interface AmenityBookingWorklist {
  items: Array<{ id: string; booking_code: string; amenity_id: string; amenity_name: string; resident_name: string; apartment_number: string; booking_date: string; start_time: string; end_time: string; review_overdue: boolean }>;
  counts: Record<'PENDING' | 'REPORTED' | 'REVIEW', number>;
  page: number;
  total_pages: number;
}

export interface AmenityListResponse {
  items: Amenity[];
  total: number;
  page: number;
  limit: number;
  total_pages: number;
  search_time_ms?: number;
  corrected_query?: string | null;
  is_fuzzy?: boolean;
  engine?: string;
  applied_query?: string;
  is_corrected?: boolean;
  fallback_reason?: string | null;
}

export interface AmenitySearchMetrics {
  days: number;
  requests: number;
  estimated_algolia_calls: number;
  zero_result_rate: number;
  fallback_rate: number;
  p50_ms: number | null;
  p95_ms: number | null;
  engines: Array<{ engine: string; requests: number }>;
  fallback_reasons: Array<{ fallback_reason: string; requests: number }>;
  dirty_documents: number;
  failed_documents: number;
  last_synced_at: string | null;
}

export interface ResidentAmenityCatalog {
  apartments: Array<{ id: string; apartment_number: string; block_id: string; block_name: string }>;
  amenities: Amenity[];
  categories: Array<{ id: string; category_name: string }>;
  today: string;
  timezone: string;
}

export interface ResidentAvailableSlot {
  slot_id: string;
  start_time: string;
  end_time: string;
  slot_label: string | null;
  remaining_bookings: number;
  remaining_attendees: number;
  available: boolean;
  reason: string | null;
  total_amount: number;
  deposit_amount: number;
}

export type ResidentBookingStatus = 'PENDING' | 'APPROVED' | 'CONFIRMED' | 'CHECKED_IN' | 'COMPLETED' | 'CANCELLED' | 'REJECTED';

export interface ResidentAmenityBooking extends Omit<AmenityBooking, 'resident_name'> {
  amenity_name: string;
  location_detail: string;
  status: ResidentBookingStatus;
  can_cancel: boolean;
  cancel_deadline: string;
  rejection_reason?: string | null;
}

export interface ResidentBookingList {
  items: ResidentAmenityBooking[];
  page: number;
  total: number;
  total_pages: number;
}

export interface ResidentBookingPayload {
  amenity_id: string;
  slot_id?: string;
  booking_date: string;
  start_time?: string;
  end_time?: string;
  attendee_count: number;
  apartment_id?: string;
  resident_notes?: string;
  accepted_rules: boolean;
}

export interface ApiError extends Error {
  status?: number;
  errors?: Record<string, string[]>;
}

export interface ResidentVisitorItem {
  id: string;
  registration_code: string;
  host_resident_user_id: string;
  apartment_id: string;
  visitor_name: string;
  visitor_phone: string;
  visitor_national_id?: string | null;
  expected_arrival_time: string;
  expected_departure_time?: string | null;
  visit_purpose: string;
  visitor_count: number;
  vehicle_license_plate?: string | null;
  qr_access_pass_code: string;
  qr_pass_status: 'ACTIVE' | 'USED' | 'EXPIRED' | 'CANCELLED';
  is_pre_approved_by_resident: boolean;
  created_at: string;
  updated_at: string;
  apartment_number?: string;
  block_name?: string;
  block_code?: string;
}

export interface ResidentVisitorListResponse {
  success: boolean;
  data: ResidentVisitorItem[];
  kpis: {
    total: number;
    active: number;
    used: number;
    cancelled: number;
    expired: number;
  };
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export interface ResidentVisitorDetailResponse {
  success: boolean;
  data: ResidentVisitorItem;
  checkin_log?: any | null;
  cooldown?: {
    cooldown_seconds: number;
    retry_after: number;
    cooldown_until: string;
  } | null;
}

export interface ReceptionVisitorItem {
  id: string;
  registration_id: string;
  registration_code: string;
  qr_access_pass_code: string;
  visitor_name: string;
  visitor_phone: string;
  visitor_national_id?: string | null;
  expected_arrival_time: string;
  expected_departure_time?: string | null;
  visit_purpose: string;
  visitor_count: number;
  vehicle_license_plate?: string | null;
  qr_pass_status: string;
  is_pre_approved_by_resident: boolean;
  status: 'EXPECTED' | 'INSIDE' | 'CHECKED_OUT' | 'CANCELLED' | 'EXPIRED';
  status_label: string;
  status_color: 'indigo' | 'blue' | 'emerald' | 'rose' | 'gray' | 'amber';
  apartment: {
    id: string;
    number: string;
    block_code?: string;
    block_name?: string;
  };
  host: {
    id?: string;
    name?: string;
    phone?: string;
  };
  checkin_log?: {
    id: string;
    checkin_time: string;
    checkout_time?: string | null;
    temp_card_id?: string | null;
    assigned_elevator_access?: string | null;
    notes?: string | null;
    receptionist_user_id?: string | null;
    security_guard_user_id?: string | null;
  } | null;
  created_at: string;
}
export interface ReceptionVisitorListResponse {
  success: boolean;
  data: ReceptionVisitorItem[];
  kpis: {
    expected_today: number;
    currently_inside: number;
    checked_out_today: number;
    total_registrations: number;
  };
  pagination: {
    current_page: number;
    per_page: number;
    total_items: number;
    total_pages: number;
    has_more: boolean;
  };
}

export interface ReceptionVisitorLookupResponse {
  success: boolean;
  data: {
    registration: any;
    status: string;
    status_label: string;
    status_color: string;
    can_checkin: boolean;
    can_checkout: boolean;
    latest_checkin_log?: any | null;
  };
}

// ================= PARCEL RECEPTION (MODULE #9) =================
export interface ParcelItem {
  id: string;
  tracking_number: string;
  courier_company: string;
  apartment_id: string;
  recipient_user_id?: string | null;
  recipient_name: string;
  recipient_phone: string;
  parcel_photo_url: string;
  stored_location_type: 'RECEPTION_DESK' | 'SMART_LOCKER' | 'SECURITY_BOOTH' | string;
  locker_compartment_id?: string | null;
  pickup_pin_code: string;
  pickup_qr_code: string;
  status: 'RECEIVED_AT_RECEPTION' | 'RECEIVED' | 'DELIVERED' | 'RETURNED' | string;
  received_at: string;
  received_by_staff_id?: string | null;
  collected_at?: string | null;
  collected_by_name?: string | null;
  collected_signature_url?: string | null;
  checkout_staff_id?: string | null;
  notes?: string | null;
  created_at: string;
  apartment?: {
    id: string;
    apartment_number: string;
    block_id?: string;
    block?: {
      id: string;
      block_name?: string;
      block_code?: string;
    };
  };
  recipient_user?: {
    id: string;
    full_name: string;
    phone_number?: string;
  } | null;
  received_by_staff?: {
    id: string;
    full_name: string;
  } | null;
  checkout_staff?: {
    id: string;
    full_name: string;
  } | null;
}

export interface ParcelListResponse {
  success: boolean;
  data: ParcelItem[];
  kpis: {
    pending_parcels: number;
    expiring_parcels?: number;
    overdue_parcels: number;
    received_today: number;
    total_parcels: number;
  };
  pagination: {
    current_page: number;
    per_page: number;
    total_items: number;
    total_pages: number;
    has_more: boolean;
  };
}

export interface ParcelDetailResponse {
  success: boolean;
  data: ParcelItem;
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

export interface SwrOptions<T> {
  onData: (data: T, isFromCache: boolean) => void;
  onSyncing?: (isSyncing: boolean) => void;
  onError?: (error: any, hasCachedData: boolean) => void;
  forceRefresh?: boolean;
  signal?: AbortSignal;
}

class ApiService {
  private getToken(): string | null {
    let token = typeof window !== 'undefined' ? localStorage.getItem('smart_cassavas_token') : null;
    const sessionStr = typeof window !== 'undefined' ? localStorage.getItem('smartcassavas_session') : null;

    // Dọn dẹp token demo sót lại nếu người dùng không hề có phiên đăng nhập active
    if (token && token.startsWith('smart_token_') && token.endsWith('_demo') && !sessionStr) {
      try {
        localStorage.removeItem('smart_cassavas_token');
        localStorage.removeItem('smart_cassavas_user');
      } catch {
        // ignore
      }
      token = null;
    }

    if (!token && sessionStr) {
      try {
        const session = JSON.parse(sessionStr);
        if (session.token || session.access_token) {
          token = session.token || session.access_token;
        } else if (session.role === 'admin' || session.isDev) {
          token = 'smart_token_admin_demo';
        } else if (session.role === 'receptionist' || session.role === 'security') {
          token = 'smart_token_reception_demo';
        } else if (session.role === 'resident' || session.role?.toLowerCase?.().includes('resident')) {
          token = 'smart_token_resident_demo';
        } else if (session.role === 'manager' || session.role?.toLowerCase?.().includes('manager')) {
          token = 'smart_token_manager_demo';
        }
      } catch {
        // ignore
      }
    }

    // Nếu không có token và không có phiên đăng nhập, tuyệt đối không tự động sinh token demo giả lập
    return token || null;
  }

  public getAuthToken(): string | null {
    return this.getToken();
  }

  public setToken(token: string) {
    localStorage.setItem('smart_cassavas_token', token);
    window.dispatchEvent(new Event('AUTH_SESSION_CHANGED'));
  }

  public removeToken() {
    localStorage.removeItem('smart_cassavas_token');
  }

  public getUser(): any | null;
  public getUser(id: string): Promise<{ success: boolean; data: UserRbac }>;
  public getUser(id?: string): any | null | Promise<{ success: boolean; data: UserRbac }> {
    if (typeof id === 'string') {
      return this.request<{ success: boolean; data: UserRbac }>(`/users/${id}`);
    }
    try {
      const token = this.getToken();
      if (!token) {
        return null;
      }
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
    try {
      localStorage.removeItem('smart_cassavas_user');
      localStorage.removeItem('smartcassavas_session');
      localStorage.removeItem('admin_auth_user');
      localStorage.removeItem('smart_user_session');
      localStorage.removeItem('smartcassavas_active_admin_tab');
      sessionStorage.clear();
    } catch {
      // ignore
    }
    amenityCache.clearAll();
    window.dispatchEvent(new Event('AUTH_SESSION_CHANGED'));
  }

  /**
   * Enhanced HTTP Request with ETag support & automatic header propagation
   */
  public async requestWithEtag<T>(
    endpoint: string,
    etag?: string | null,
    options: RequestInit = {}
  ): Promise<{ data: T | null; etag: string | null; notModified: boolean }> {
    const url = `${API_BASE_URL}${endpoint}`;
    const token = this.getToken();

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers as Record<string, string> || {}),
    };

    if (options.body instanceof FormData) {
      delete headers['Content-Type'];
    }

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    if (etag) {
      headers['If-None-Match'] = etag;
    }

    try {
      const response = await fetch(url, {
        ...options,
        headers,
      });

      if (response.status === 304) {
        return { data: null, etag: response.headers.get('ETag') || etag || null, notModified: true };
      }

      if (response.status === 204) {
        return { data: {} as T, etag: response.headers.get('ETag'), notModified: false };
      }

      const newEtag = response.headers.get('ETag');
      const data = await response.json().catch(() => null);

      if (!response.ok) {
        const msg = data?.detail || data?.message || `Yêu cầu thất bại (${response.status})`;
        const error: any = new Error(typeof msg === 'string' ? msg : JSON.stringify(msg));
        error.status = response.status;
        error.data = data;
        error.errors = data?.errors;
        throw error;
      }

      return { data: data as T, etag: newEtag, notModified: false };
    } catch (err: any) {
      if (err?.name === 'AbortError') {
        return { data: null, etag: null, notModified: true };
      }
      throw err;
    }
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const res = await this.requestWithEtag<T>(endpoint, null, options);
    if ((!res || res.data === null) && options.signal?.aborted) {
      const abortError = new Error('Request was aborted');
      abortError.name = 'AbortError';
      throw abortError;
    }
    return res.data as T;
  }

  // ================= AUTH =================
  async login(identifier: string, password: string) {
    const res = await this.request<{
      access_token: string;
      token_type: string;
      user: any;
    }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username: identifier, identifier, password }),
    });
    if (res.access_token) {
      this.setToken(res.access_token);
    }
    if (res.user) {
      this.setUser(res.user);
    }
    amenityCache.clearAll();
    return res;
  }

  async getMe() {
    return this.request<any>('/auth/me');
  }

  async register(payload: {
    full_name: string;
    email: string;
    phone_number: string;
    password: string;
    apartment_id?: string;
    apartment_number?: string;
    resident_type?: 'OWNER' | 'TENANT';
    otp?: string;
    verification_token?: string;
  }) {
    const res = await this.request<{
      success: boolean;
      message: string;
      access_token?: string;
      token_type?: string;
      user?: any;
    }>('/auth/register', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    if (res.access_token) {
      this.setToken(res.access_token);
    }
    if (res.user) {
      this.setUser(res.user);
    }
    return res;
  }

  async sendRegisterOtp(email: string) {
    return this.request<{
      success: boolean;
      message: string;
      email?: string;
      debug_otp?: string | null;
    }>('/auth/send-register-otp', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
  }

  async verifyRegisterOtp(email: string, otp: string) {
    return this.request<{
      success: boolean;
      message: string;
      verification_token?: string;
    }>('/auth/verify-register-otp', {
      method: 'POST',
      body: JSON.stringify({ email, otp }),
    });
  }

  async forgotPassword(email: string) {
    return this.request<{
      success: boolean;
      message: string;
      email?: string;
      debug_otp?: string | null;
    }>('/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
  }

  async verifyOtp(email: string, otp: string) {
    return this.request<{
      success: boolean;
      message: string;
      reset_token?: string;
    }>('/auth/verify-otp', {
      method: 'POST',
      body: JSON.stringify({ email, otp }),
    });
  }

  async resetPassword(payload: {
    email: string;
    reset_token: string;
    password: string;
    password_confirmation: string;
  }) {
    return this.request<{
      success: boolean;
      message: string;
    }>('/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async getPublicApartments() {
    return this.request<{
      success: boolean;
      data: Array<{ id: string; apartment_number: string; room_type: string }>;
    }>('/auth/public-apartments');
  }

  async getRentalListings(blockCode?: string) {
    const query = blockCode ? `?block_code=${encodeURIComponent(blockCode)}` : '';
    return this.request<{
      success: boolean;
      data: {
        blocks: Array<{
          id: string;
          block_code: string;
          block_name: string;
          total_floors: number;
          total_apartments: number;
          address_line?: string;
          hotline_phone?: string;
        }>;
        listings: Array<{
          id: string;
          apartment_number: string;
          block_code: string;
          block_name: string;
          room_type: string;
          bedroom_count: number;
          bathroom_count: number;
          area_sqm: number;
          has_balcony: boolean;
          furnished_status: string;
          furnished_text: string;
          monthly_rent: number;
          monthly_rent_formatted: string;
          owner_name: string;
          is_available: boolean;
          view_direction: string;
        }>;
      };
    }>(`/auth/rental-listings${query}`);
  }

  // ================= CATEGORIES =================
  async getCategories(forceRefresh: boolean = false): Promise<Category[]> {
    const key = 'amenities:categories';
    const cached = amenityCache.get<Category[]>(key);

    if (!forceRefresh && cached.exists && Array.isArray(cached.data) && cached.data.length > 0 && cached.isFresh) {
      return cached.data;
    }

    return amenityCache.dedupe(key, async () => {
      try {
        const { data, etag, notModified } = await this.requestWithEtag<any>(
          '/admin/amenity-categories',
          cached.etag
        );
        if (notModified && Array.isArray(cached.data) && cached.data.length > 0) {
          amenityCache.touch(key);
          return cached.data;
        }
        const list: Category[] = Array.isArray(data)
          ? data
          : (Array.isArray(data?.data) ? data.data : (Array.isArray(data?.value) ? data.value : []));

        if (list.length > 0) {
          amenityCache.set(key, list, etag, 10 * 60 * 1000); // 10 mins fresh
          return list;
        }
        return Array.isArray(cached.data) ? cached.data : [];
      } catch (err) {
        if (Array.isArray(cached.data)) return cached.data;
        throw err;
      }
    });
  }

  async createCategory(payload: {
    category_name: string;
    category_code: string;
    icon_name?: string | null;
    description?: string | null;
  }): Promise<Category> {
    const result = await this.request<Category>('/admin/amenity-categories', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateCategories();
    amenityCache.broadcastMutation('CATEGORY_CHANGED', result.id);
    return result;
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
    const result = await this.request<Category>(`/admin/amenity-categories/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateCategories();
    amenityCache.broadcastMutation('CATEGORY_CHANGED', id);
    return result;
  }

  async deleteCategory(id: string): Promise<void> {
    const result = await this.request<void>(`/admin/amenity-categories/${id}`, {
      method: 'DELETE',
    });
    amenityCache.invalidateCategories();
    amenityCache.broadcastMutation('CATEGORY_CHANGED', id);
    return result;
  }

  // ================= AMENITIES & SWR =================

  /**
   * Stale-While-Revalidate fetcher for Amenities List
   * 
   * 1. Looks up cache: if found, invokes onData immediately (0ms).
   * 2. If fresh and not forceRefresh: ends immediately (no background call).
   * 3. If stale or no cache: dispatches background fetch with ETag.
   * 4. If server returns 304: touches cache, notifies syncing complete.
   * 5. If server returns 200: updates cache & invokes onData with fresh data.
   * 6. If network fails: retains cached data and informs onError.
   */
  public async getAmenitiesSwr(
    params: {
      search?: string;
      category_id?: string;
      block_id?: string;
      is_active?: boolean;
      page?: number;
      limit?: number;
      sort?: string;
    } = {},
    options: SwrOptions<AmenityListResponse>
  ): Promise<void> {
    const user = this.getUser();
    const userId = user?.id || 'admin';
    const cacheKey = amenityCache.buildAmenityListKey(params, userId);
    const lookup = amenityCache.get<AmenityListResponse>(cacheKey);

    let hasRenderedCache = false;

    // Fast initial render from cache
    if (lookup.exists && lookup.data) {
      options.onData(lookup.data, true);
      hasRenderedCache = true;
    }

    // Zero-latency optimization: Nếu cache vẫn còn Fresh và không ép buộc làm mới, kết thúc ngay lập tức (0ms)
    if (!options.forceRefresh && lookup.exists && lookup.data && lookup.isFresh) {
      if (options.onSyncing) options.onSyncing(false);
      return;
    }

    if (options.onSyncing) options.onSyncing(true);

    const query = new URLSearchParams();
    if (params.search) query.set('search', params.search);
    if (params.category_id) query.set('category_id', params.category_id);
    if (params.block_id) query.set('block_id', params.block_id);
    if (params.is_active !== undefined) query.set('is_active', String(params.is_active));
    if (params.page) query.set('page', String(params.page));
    if (params.limit) query.set('limit', String(params.limit));
    if (params.sort) query.set('sort', params.sort);

    const endpoint = `/admin/amenities?${query.toString()}`;

    try {
      await amenityCache.dedupe(cacheKey, async () => {
        const { data, etag, notModified } = await this.requestWithEtag<AmenityListResponse>(
          endpoint,
          lookup.etag,
          { signal: options.signal }
        );

        if (options.signal?.aborted) {
          if (options.onSyncing) options.onSyncing(false);
          return;
        }

        if (notModified) {
          // Server confirmed data has not changed!
          amenityCache.touch(cacheKey);
          if (options.onSyncing) options.onSyncing(false);
          return;
        }

        if (data) {
          amenityCache.set(cacheKey, data, etag);
          options.onData(data, false);
        }
        if (options.onSyncing) options.onSyncing(false);
      });
    } catch (err: any) {
      if (err?.name === 'AbortError' || options.signal?.aborted) {
        if (options.onSyncing) options.onSyncing(false);
        return;
      }
      if (options.onSyncing) options.onSyncing(false);
      if (options.onError) {
        options.onError(err, hasRenderedCache);
      } else if (!hasRenderedCache) {
        throw err;
      }
    }
  }

  /**
   * Autocomplete search suggestions (giới hạn 5 kết quả, cancelable, LRU memory cached)
   */
  async getSearchSuggestions(
    query: string,
    type: string = 'amenities',
    signal?: AbortSignal
  ): Promise<Array<{ id: string; label: string; code?: string; type?: string; category?: string }>> {
    const trimmed = query.trim();
    if (!trimmed || trimmed.length < 2) return [];

    const cacheKey = `${this.getUser()?.id ?? 'anonymous'}:${type}:${trimmed.toLowerCase()}`;
    const cached = suggestionCache.get(cacheKey);
    if (cached && Date.now() - cached.ts < SUGGESTION_TTL) {
      return cached.data;
    }

    try {
      const res = await this.request<{
        suggestions: Array<{ id: string; label: string; code?: string; type?: string; category?: string }>;
      }>(`/search/suggestions?q=${encodeURIComponent(trimmed)}&type=${encodeURIComponent(type)}&limit=5`, {
        signal,
      });
      const suggestions = res.suggestions || [];
      if (suggestionCache.size >= MAX_SUGGESTION_CACHE) {
        const firstKey = suggestionCache.keys().next().value;
        if (firstKey) suggestionCache.delete(firstKey);
      }
      suggestionCache.set(cacheKey, { data: suggestions, ts: Date.now() });
      return suggestions;
    } catch (err: any) {
      if (err?.name === 'AbortError' || signal?.aborted) return [];
      return [];
    }
  }

  /**
   * Smart Search for Amenities (Fuzzy, Vietnamese Spell Correction & Relevance Ranking)
   * GET /api/amenities/search?q=ku%20nghi%20duon
   */
  async searchSmartAmenities(
    query: string,
    filters: {
      category_id?: string;
      block_id?: string;
      is_active?: boolean;
      page?: number;
      limit?: number;
      sort?: string;
    } = {},
    options: { signal?: AbortSignal } = {}
  ): Promise<{
    data: Amenity[];
    query: string;
    normalized_query: string;
    corrected_query: string;
    total: number;
    search_time_ms: number;
  }> {
    const q = new URLSearchParams();
    q.set('q', query);
    if (filters.category_id) q.set('category_id', filters.category_id);
    if (filters.block_id) q.set('block_id', filters.block_id);
    if (filters.is_active !== undefined) q.set('is_active', String(filters.is_active));
    if (filters.page) q.set('page', String(filters.page));
    if (filters.limit) q.set('limit', String(filters.limit));
    if (filters.sort) q.set('sort', filters.sort);

    return this.request<{
      data: Amenity[];
      query: string;
      normalized_query: string;
      corrected_query: string;
      total: number;
      search_time_ms: number;
    }>(`/amenities/search?${q.toString()}`, {
      signal: options.signal,
    });
  }

  /**
   * Regular getAmenities (uses SWR cache behind the scenes if available)
   */
  async getAmenities(params: {
    search?: string;
    category_id?: string;
    block_id?: string;
    is_active?: boolean;
    page?: number;
    limit?: number;
    sort?: string;
  } = {}): Promise<AmenityListResponse> {
    const user = this.getUser();
    const userId = user?.id || 'admin';
    const cacheKey = amenityCache.buildAmenityListKey(params, userId);
    const lookup = amenityCache.get<AmenityListResponse>(cacheKey);

    if (lookup.exists && lookup.data && lookup.isFresh) {
      return lookup.data;
    }

    const query = new URLSearchParams();
    if (params.search) query.set('search', params.search);
    if (params.category_id) query.set('category_id', params.category_id);
    if (params.block_id) query.set('block_id', params.block_id);
    if (params.is_active !== undefined) query.set('is_active', String(params.is_active));
    if (params.page) query.set('page', String(params.page));
    if (params.limit) query.set('limit', String(params.limit));
    if (params.sort) query.set('sort', params.sort);

    const { data, etag, notModified } = await this.requestWithEtag<AmenityListResponse>(
      `/admin/amenities?${query.toString()}`,
      lookup.etag
    );

    if (notModified && lookup.data) {
      amenityCache.touch(cacheKey);
      return lookup.data;
    }

    if (data) {
      amenityCache.set(cacheKey, data, etag);
      return data;
    }

    return lookup.data || { items: [], total: 0, page: 1, limit: 10, total_pages: 1 };
  }

  /**
   * Prefetch an amenity page into cache silently
   */
  public prefetchAmenities(params: {
    search?: string;
    category_id?: string;
    block_id?: string;
    is_active?: boolean;
    page?: number;
    limit?: number;
    sort?: string;
  } = {}): void {
    const user = this.getUser();
    const userId = user?.id || 'admin';
    const cacheKey = amenityCache.buildAmenityListKey(params, userId);
    const lookup = amenityCache.get<AmenityListResponse>(cacheKey);

    if (lookup.exists && lookup.isFresh) {
      return; // Already fresh in cache
    }

    // Trigger silent fetch in background
    this.getAmenities(params).catch(() => {});
  }

  async getAmenity(id: string, forceRefresh: boolean = false): Promise<Amenity> {
    const key = `amenity:detail:${id}`;
    const lookup = amenityCache.get<Amenity>(key);
    if (!forceRefresh && lookup.exists && lookup.data && lookup.isFresh) {
      return lookup.data;
    }

    const item = await this.request<Amenity>(`/admin/amenities/${id}`);
    amenityCache.set(key, item, null, 2 * 60 * 1000);
    return item;
  }

  async createAmenity(payload: Partial<Amenity>): Promise<Amenity> {
    const result = await this.request<Amenity>('/admin/amenities', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    suggestionCache.clear();
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_CREATED', result.id);
    return result;
  }

  async updateAmenity(id: string, payload: Partial<Amenity>): Promise<Amenity> {
    const result = await this.request<Amenity>(`/admin/amenities/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
    suggestionCache.clear();
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_UPDATED', id);
    return result;
  }

  async getAmenityClosureImpact(id: string, period: { blackout_date?: string; start_time?: string | null; end_time?: string | null } = {}): Promise<AmenityClosureImpact> {
    const query = new URLSearchParams();
    Object.entries(period).forEach(([key, value]) => { if (value) query.set(key, value); });
    return this.request(`/admin/amenities/${id}/closure-impact?${query}`, { cache: 'no-store' });
  }

  async patchAmenityStatus(id: string, is_active: boolean, updated_at: string, closure: { booking_action?: 'keep' | 'cancel'; reason?: string; confirmation_token?: string } = {}): Promise<Amenity> {
    const result = await this.request<Amenity>(`/admin/amenities/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active, updated_at, ...closure }),
    });
    suggestionCache.clear();
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_STATUS_CHANGED', id);
    if (closure.booking_action === 'cancel') { amenityCache.invalidateBookings(id); amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', id); }
    return result;
  }

  async deleteAmenity(id: string): Promise<void> {
    const result = await this.request<void>(`/admin/amenities/${id}`, {
      method: 'DELETE',
    });
    suggestionCache.clear();
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_DELETED', id);
    return result;
  }

  // ================= TIME SLOTS =================
  async getTimeSlots(amenityId: string): Promise<TimeSlot[]> {
    const key = `amenity:${amenityId}:slots`;
    const lookup = amenityCache.get<TimeSlot[]>(key);
    if (lookup.exists && lookup.data && lookup.isFresh) {
      return lookup.data;
    }

    const slots = await this.request<TimeSlot[]>(`/admin/amenities/${amenityId}/time-slots`);
    amenityCache.set(key, slots, null, 5 * 60 * 1000);
    return slots;
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
    const result = await this.request<TimeSlot>(`/admin/amenities/${amenityId}/time-slots`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateSlots(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_SLOT_UPDATED', amenityId, result.id);
    return result;
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
    const result = await this.request<TimeSlot>(`/admin/amenities/${amenityId}/time-slots/${slotId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateSlots(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_SLOT_UPDATED', amenityId, slotId);
    return result;
  }

  async patchTimeSlotStatus(amenityId: string, slotId: string, is_active: boolean): Promise<TimeSlot> {
    const result = await this.request<TimeSlot>(`/admin/amenities/${amenityId}/time-slots/${slotId}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active }),
    });
    amenityCache.invalidateSlots(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_SLOT_UPDATED', amenityId, slotId);
    return result;
  }

  async deleteTimeSlot(amenityId: string, slotId: string): Promise<void> {
    const result = await this.request<void>(`/admin/amenities/${amenityId}/time-slots/${slotId}`, {
      method: 'DELETE',
    });
    amenityCache.invalidateSlots(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_SLOT_UPDATED', amenityId, slotId);
    return result;
  }

  // ================= BLACKOUTS =================
  async getBlackouts(amenityId: string): Promise<Blackout[]> {
    const key = `amenity:${amenityId}:blackouts`;
    const lookup = amenityCache.get<Blackout[]>(key);
    if (lookup.exists && lookup.data && lookup.isFresh) {
      return lookup.data;
    }

    const blackouts = await this.request<Blackout[]>(`/admin/amenities/${amenityId}/blackouts`);
    amenityCache.set(key, blackouts, null, 5 * 60 * 1000);
    return blackouts;
  }

  async createBlackout(
    amenityId: string,
    payload: {
      blackout_date: string;
      start_time?: string | null;
      end_time?: string | null;
      reason: string;
      confirmation_token?: string;
    }
  ): Promise<Blackout> {
    const result = await this.request<Blackout>(`/admin/amenities/${amenityId}/blackouts`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateBlackouts(amenityId);
    amenityCache.invalidateBookings(amenityId);
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', amenityId);
    amenityCache.broadcastMutation('AMENITY_BLACKOUT_CHANGED', amenityId, result.id);
    return result;
  }

  async updateBlackout(
    amenityId: string,
    blackoutId: string,
    payload: {
      blackout_date: string;
      start_time?: string | null;
      end_time?: string | null;
      reason: string;
      confirmation_token?: string;
    }
  ): Promise<Blackout> {
    const result = await this.request<Blackout>(`/admin/amenities/${amenityId}/blackouts/${blackoutId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateBlackouts(amenityId);
    amenityCache.invalidateBookings(amenityId);
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', amenityId);
    amenityCache.broadcastMutation('AMENITY_BLACKOUT_CHANGED', amenityId, blackoutId);
    return result;
  }

  async deleteBlackout(amenityId: string, blackoutId: string): Promise<void> {
    const result = await this.request<void>(`/admin/amenities/${amenityId}/blackouts/${blackoutId}`, {
      method: 'DELETE',
    });
    amenityCache.invalidateBlackouts(amenityId);
    amenityCache.broadcastMutation('AMENITY_BLACKOUT_CHANGED', amenityId, blackoutId);
    return result;
  }

  // ================= BOOKINGS =================
  async getAmenityBookings(amenityId: string, forceRefresh: boolean = false, signal?: AbortSignal): Promise<AmenityBooking[]> {
    const key = `amenity:${amenityId}:bookings`;
    const lookup = amenityCache.get<AmenityBooking[]>(key);
    if (!forceRefresh && lookup.exists && lookup.data && lookup.isFresh) {
      return lookup.data;
    }

    const bookings = await this.request<AmenityBooking[]>(`/admin/amenities/${amenityId}/bookings`, { signal, cache: 'no-store' });
    if (!signal?.aborted) amenityCache.set(key, bookings, null, 2 * 60 * 1000);
    return bookings;
  }

  async patchAmenityBookingStatus(
    amenityId: string,
    bookingId: string,
    status: string,
    adminNotes?: string
  ): Promise<AmenityBooking> {
    const result = await this.request<AmenityBooking>(`/admin/amenities/${amenityId}/bookings/${bookingId}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status, admin_notes: adminNotes, ...(status === 'REJECTED' ? { rejection_reason: adminNotes } : {}) }),
    });
    amenityCache.invalidateBookings(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', amenityId, bookingId);
    return result;
  }

  async cancelAmenityBooking(amenityId: string, bookingId: string, reason?: string): Promise<void> {
    const result = await this.request<void>(`/admin/amenities/${amenityId}/bookings/${bookingId}/cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    });
    amenityCache.invalidateBookings(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', amenityId, bookingId);
    return result;
  }

  async bookAmenity(amenityId: string, payload: any): Promise<AmenityBooking> {
    const result = await this.request<AmenityBooking>(`/api/v1/amenities/${amenityId}/bookings`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateBookings(amenityId);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', amenityId, result.id);
    return result;
  }

  // ================= META =================
  async getBlocks(forceRefresh: boolean = false): Promise<BlockOption[]> {
    const key = 'amenities:blocks';
    const cached = amenityCache.get<BlockOption[]>(key);

    if (!forceRefresh && cached.exists && Array.isArray(cached.data) && cached.data.length > 0 && cached.isFresh) {
      return cached.data;
    }

    return amenityCache.dedupe(key, async () => {
      try {
        let rawBlocks: any;
        try {
          rawBlocks = await this.request<any>('/admin/blocks');
        } catch {
          rawBlocks = await this.request<any>('/meta/blocks');
        }
        const blocks: BlockOption[] = Array.isArray(rawBlocks)
          ? rawBlocks
          : (Array.isArray(rawBlocks?.data) ? rawBlocks.data : (Array.isArray(rawBlocks?.value) ? rawBlocks.value : []));

        if (blocks.length > 0) {
          amenityCache.set(key, blocks, null, 15 * 60 * 1000); // 15 mins fresh
          return blocks;
        }
        return Array.isArray(cached.data) ? cached.data : [];
      } catch (err) {
        if (Array.isArray(cached.data)) return cached.data;
        throw err;
      }
    });
  }

  // ================= RESIDENT PORTAL =================
  async getResidentOverview(userId?: string): Promise<any> {
    const query = userId ? `?user_id=${encodeURIComponent(userId)}` : '';
    return this.request<any>(`/resident/overview${query}`);
  }

  async getResidentAmenities(signal?: AbortSignal): Promise<ResidentAmenityCatalog> {
    return this.request('/resident/amenities', { signal, cache: 'no-store' });
  }

  async getAmenitySearchMetrics(signal?: AbortSignal): Promise<AmenitySearchMetrics> {
    return this.request('/admin/amenity-search/metrics', { signal, cache: 'no-store' });
  }

  async getResidentAvailability(amenityId: string, date: string, apartmentId: string, signal?: AbortSignal): Promise<{ slots: ResidentAvailableSlot[] }> {
    const query = new URLSearchParams({ date, apartment_id: apartmentId });
    return this.request(`/resident/amenities/${amenityId}/availability?${query}`, { signal, cache: 'no-store' });
  }

  async getResidentBookings(status: string, page: number, signal?: AbortSignal): Promise<ResidentBookingList> {
    const query = new URLSearchParams({ page: String(page) });
    if (status) query.set('status', status);
    return this.request(`/resident/amenity-bookings?${query}`, { signal, cache: 'no-store' });
  }

  async getResidentBooking(id: string, signal?: AbortSignal): Promise<ResidentAmenityBooking> {
    return this.request(`/resident/amenity-bookings/${id}`, { signal, cache: 'no-store' });
  }

  async getResidentBookingPayment(id: string, signal?: AbortSignal): Promise<{ payment: AmenityBookingPayment | null }> {
    return this.request(`/resident/amenity-bookings/${id}/payment`, { signal, cache: 'no-store' });
  }

  async createResidentPaymentCheckout(id: string): Promise<{ environment: 'sandbox' | 'production'; action: string; fields: Record<string, string> }> {
    return this.request(`/resident/amenity-bookings/${id}/payment/checkout`, { method: 'POST', cache: 'no-store' });
  }

  async getAmenityNotifications(audience: 'resident' | 'admin', page: number, unread: boolean, signal?: AbortSignal): Promise<AmenityNotificationFeed> {
    const path = audience === 'resident' ? '/resident/amenity-notifications' : '/admin/amenity-booking-notifications';
    return this.request(`${path}?page=${page}&unread=${unread ? 1 : 0}`, { signal, cache: 'no-store' });
  }

  async readAmenityNotification(audience: 'resident' | 'admin', id: string): Promise<{ success: boolean }> {
    const path = audience === 'resident' ? '/resident/amenity-notifications' : '/admin/amenity-booking-notifications';
    return this.request(`${path}/${encodeURIComponent(id)}/read`, { method: 'POST' });
  }

  async getAmenityBookingWorklist(bucket: string, page: number, signal?: AbortSignal): Promise<AmenityBookingWorklist> {
    return this.request(`/admin/amenity-booking-worklist?bucket=${encodeURIComponent(bucket)}&page=${page}`, { signal, cache: 'no-store' });
  }

  async reportResidentBookingPayment(booking: Pick<AmenityBooking, 'id' | 'amenity_id'>): Promise<{ payment: AmenityBookingPayment }> {
    const result = await this.request<{ payment: AmenityBookingPayment }>(`/resident/amenity-bookings/${booking.id}/payment/report`, { method: 'POST' });
    amenityCache.invalidateBookings(booking.amenity_id);
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', booking.amenity_id, booking.id);
    return result;
  }

  async decideAmenityBookingPayment(booking: Pick<AmenityBooking, 'id' | 'amenity_id'>, payload: { bank_transaction_id: string; received_amount: number; received_at: string } | { reason: string }): Promise<{ payment: AmenityBookingPayment }> {
    const action = 'reason' in payload ? 'reject' : 'confirm';
    const result = await this.request<{ payment: AmenityBookingPayment }>(`/admin/amenities/${booking.amenity_id}/bookings/${booking.id}/payment/${action}`, { method: 'POST', body: JSON.stringify(payload) });
    amenityCache.invalidateBookings(booking.amenity_id);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', booking.amenity_id, booking.id);
    return result;
  }

  async cancelResidentBooking(booking: ResidentAmenityBooking, reason: string): Promise<{ success: boolean; booking: ResidentAmenityBooking }> {
    const result = await this.request<{ success: boolean; booking: ResidentAmenityBooking }>(`/resident/amenity-bookings/${booking.id}/cancel`, {
      method: 'POST', body: JSON.stringify({ reason }),
    });
    amenityCache.invalidateBookings(booking.amenity_id);
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', booking.amenity_id, booking.id);
    return result;
  }

  async createResidentBooking(payload: ResidentBookingPayload): Promise<{ success: boolean; booking: ResidentAmenityBooking }> {
    const result = await this.request<{ success: boolean; booking: ResidentAmenityBooking }>('/resident/amenity-bookings', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateAmenities();
    amenityCache.invalidateBookings(payload.amenity_id);
    amenityCache.broadcastMutation('AMENITY_BOOKING_CHANGED', payload.amenity_id, result.booking.id);
    return result;
  }

  // ================= MODULE #7: KHAI BÁO KHÁCH VIẾNG THĂM (CƯ DÂN) =================
  async getResidentVisitors(params: {
    search?: string;
    status?: string;
    from_date?: string;
    to_date?: string;
    page?: number;
    limit?: number;
  } = {}): Promise<ResidentVisitorListResponse> {
    const q = new URLSearchParams();
    if (params.search) q.append('search', params.search);
    if (params.status) q.append('status', params.status);
    if (params.from_date) q.append('from_date', params.from_date);
    if (params.to_date) q.append('to_date', params.to_date);
    if (params.page) q.append('page', String(params.page));
    if (params.limit) q.append('limit', String(params.limit));

    const qs = q.toString();
    return this.request<ResidentVisitorListResponse>(`/resident/visitors${qs ? `?${qs}` : ''}`, {
      method: 'GET',
    });
  }

  async getResidentVisitorDetail(id: string): Promise<ResidentVisitorDetailResponse> {
    return this.request<ResidentVisitorDetailResponse>(`/resident/visitors/${id}`, {
      method: 'GET',
    });
  }

  async createResidentVisitor(payload: {
    visitor_name: string;
    visitor_phone: string;
    visitor_national_id?: string;
    expected_arrival_time: string;
    expected_departure_time?: string;
    visit_purpose?: string;
    visitor_count?: number;
    vehicle_license_plate?: string;
    apartment_id?: string;
  }): Promise<{ success: boolean; message: string; data: ResidentVisitorItem }> {
    return this.request<{ success: boolean; message: string; data: ResidentVisitorItem }>('/resident/visitors', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async updateResidentVisitor(
    id: string,
    payload: {
      visitor_name?: string;
      visitor_phone?: string;
      visitor_national_id?: string;
      expected_arrival_time?: string;
      expected_departure_time?: string;
      visit_purpose?: string;
      visitor_count?: number;
      vehicle_license_plate?: string;
    }
  ): Promise<{ success: boolean; message: string; data: ResidentVisitorItem }> {
    return this.request<{ success: boolean; message: string; data: ResidentVisitorItem }>(`/resident/visitors/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  }

  async cancelResidentVisitor(
    id: string,
    reason?: string
  ): Promise<{ success: boolean; message: string; data: ResidentVisitorItem }> {
    return this.request<{ success: boolean; message: string; data: ResidentVisitorItem }>(`/resident/visitors/${id}/cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    });
  }

  async payResidentInvoice(invoiceId: string): Promise<any> {
    return this.request<any>(`/resident/invoices/${invoiceId}/pay`, {
      method: 'POST',
    });
  }

  // ================= RECEPTION VISITOR CHECK-IN & CHECK-OUT =================
  async getReceptionVisitors(
    params: {
      search?: string;
      status?: string;
      from_date?: string;
      to_date?: string;
      apartment_id?: string;
      page?: number;
      limit?: number;
      per_page?: number;
    } = {},
    options: RequestInit = {}
  ): Promise<ReceptionVisitorListResponse> {
    const q = new URLSearchParams();
    if (params.search) q.append('search', params.search);
    if (params.status) q.append('status', params.status);
    if (params.from_date) q.append('from_date', params.from_date);
    if (params.to_date) q.append('to_date', params.to_date);
    if (params.apartment_id) q.append('apartment_id', params.apartment_id);
    if (params.page) q.append('page', String(params.page));
    if (params.limit || params.per_page) q.append('limit', String(params.limit || params.per_page));

    return this.request<ReceptionVisitorListResponse>(`/reception/visitors?${q.toString()}`, options);
  }

  async lookupReceptionVisitor(code: string, options: RequestInit = {}): Promise<ReceptionVisitorLookupResponse> {
    const q = new URLSearchParams();
    q.append('code', code);
    return this.request<ReceptionVisitorLookupResponse>(`/reception/visitors/lookup?${q.toString()}`, options);
  }

  async checkInReceptionVisitor(payload: {
    registration_id?: string;
    id?: string;
    qr_code?: string;
    code?: string;
    national_id_number?: string;
    temp_card_id?: string;
    assigned_elevator_access?: any;
    notes?: string;
  }): Promise<{ success: boolean; message: string; data: any }> {
    return this.request<{ success: boolean; message: string; data: any }>('/reception/visitors/check-in', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async checkOutReceptionVisitor(payload: {
    checkin_log_id?: string;
    registration_id?: string;
    id?: string;
    notes?: string;
  }): Promise<{ success: boolean; message: string; data: any }> {
    return this.request<{ success: boolean; message: string; data: any }>('/reception/visitors/check-out', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  // ================= RECEPTION PARCEL RECEPTION & UPLOAD (MODULE #9) =================
  async getReceptionParcels(
    params: {
      search?: string;
      status?: string;
      apartment_id?: string;
      courier_company?: string;
      from_date?: string;
      to_date?: string;
      page?: number;
      limit?: number;
      per_page?: number;
    } = {},
    options: RequestInit = {}
  ): Promise<ParcelListResponse> {
    const q = new URLSearchParams();
    if (params.search) q.append('search', params.search);
    if (params.status) q.append('status', params.status);
    if (params.apartment_id) q.append('apartment_id', params.apartment_id);
    if (params.courier_company) q.append('courier_company', params.courier_company);
    if (params.from_date) q.append('from_date', params.from_date);
    if (params.to_date) q.append('to_date', params.to_date);
    if (params.page) q.append('page', String(params.page));
    if (params.limit || params.per_page) q.append('limit', String(params.limit || params.per_page));

    return this.request<ParcelListResponse>(`/reception/parcels?${q.toString()}`, options);
  }

  async getParcels(
    params: {
      search?: string;
      status?: string;
      apartment_id?: string;
      courier_company?: string;
      from_date?: string;
      to_date?: string;
      page?: number;
      limit?: number;
      per_page?: number;
    } = {},
    options: RequestInit = {}
  ): Promise<ParcelListResponse> {
    return this.getReceptionParcels(params, options);
  }

  async receiveParcel(formData: FormData): Promise<{ success: boolean; message: string; data: ParcelItem }> {
    return this.request<{ success: boolean; message: string; data: ParcelItem }>('/reception/parcels', {
      method: 'POST',
      body: formData,
    });
  }

  async getParcelDetail(id: string, options: RequestInit = {}): Promise<ParcelDetailResponse> {
    return this.request<ParcelDetailResponse>(`/reception/parcels/${id}`, options);
  }

  async updateParcel(id: string, formData: FormData | object): Promise<{ success: boolean; message: string; data: ParcelItem }> {
    const isFormData = formData instanceof FormData;
    return this.request<{ success: boolean; message: string; data: ParcelItem }>(`/reception/parcels/${id}`, {
      method: isFormData ? 'POST' : 'PUT',
      body: isFormData ? formData : JSON.stringify(formData),
    });
  }

  async deleteParcel(id: string): Promise<{ success: boolean; message: string }> {
    return this.request<{ success: boolean; message: string }>(`/reception/parcels/${id}`, {
      method: 'DELETE',
    });
  }

  async searchParcelRecipients(keyword: string = '', apartmentId?: string): Promise<{ success: boolean; data: any[] }> {
    const q = new URLSearchParams();
    if (keyword) q.append('search', keyword);
    if (apartmentId) q.append('apartment_id', apartmentId);
    return this.request<{ success: boolean; data: any[] }>(`/reception/parcels/recipients/search?${q.toString()}`);
  }

  async searchParcelApartments(keyword: string = ''): Promise<{ success: boolean; data: any[] }> {
    const q = new URLSearchParams();
    if (keyword) q.append('search', keyword);
    return this.request<{ success: boolean; data: any[] }>(`/reception/parcels/apartments/search?${q.toString()}`);
  }

  async checkoutReceptionParcel(
    id: string,
    data: {
      collected_by_name?: string;
      pin_code?: string;
      qr_code?: string;
      notes?: string;
      collected_signature_url?: string;
    } = {}
  ): Promise<{ success: boolean; message: string; data: ParcelItem }> {
    return this.request<{ success: boolean; message: string; data: ParcelItem }>(`/reception/parcels/${id}/check-out`, {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async lookupReceptionParcel(
    code: string
  ): Promise<{ success: boolean; data: ParcelItem | null; can_checkout?: boolean; message?: string }> {
    const q = new URLSearchParams({ code });
    return this.request<{ success: boolean; data: ParcelItem | null; can_checkout?: boolean; message?: string }>(
      `/reception/parcels/lookup?${q.toString()}`
    );
  }

  async remindReceptionParcel(
    id: string
  ): Promise<{ success: boolean; message: string; parcel?: ParcelItem; recipient_user_id?: string }> {
    return this.request<{ success: boolean; message: string; parcel?: ParcelItem; recipient_user_id?: string }>(
      `/reception/parcels/${id}/remind`,
      { method: 'POST' }
    );
  }

  async remindExpiringParcels(): Promise<{ success: boolean; count?: number; message: string }> {
    return this.request<{ success: boolean; count?: number; message: string }>(
      '/reception/parcels/remind-expiring',
      { method: 'POST' }
    );
  }

  // ================= WEB PUSH NOTIFICATIONS =================
  async registerPushSubscription(subscriptionData: {
    endpoint: string;
    keys: { p256dh: string; auth: string };
    content_encoding?: string;
  }): Promise<{ success: boolean; message: string; subscription_id?: string }> {
    return this.request<{ success: boolean; message: string; subscription_id?: string }>('/notifications/push-subscription', {
      method: 'POST',
      body: JSON.stringify(subscriptionData),
    });
  }

  async unregisterPushSubscription(endpoint: string): Promise<{ success: boolean; message: string }> {
    return this.request<{ success: boolean; message: string }>('/notifications/push-subscription', {
      method: 'DELETE',
      body: JSON.stringify({ endpoint }),
    });
  }

  async getPushNotificationStatus(): Promise<{ success: boolean; is_subscribed: boolean; active_devices: number }> {
    return this.request<{ success: boolean; is_subscribed: boolean; active_devices: number }>('/notifications/push-status');
  }

  async getVapidPublicKey(): Promise<{ success: boolean; public_key: string | null; is_configured: boolean }> {
    return this.request<{ success: boolean; public_key: string | null; is_configured: boolean }>('/notifications/vapid-public-key');
  }

  // ================= RBAC MANAGEMENT =================
  async getUsers(params: { search?: string; role?: string; status?: string; page?: number; limit?: number } = {}) {
    const q = new URLSearchParams();
    if (params.search) q.append('search', params.search);
    if (params.role) q.append('role', params.role);
    if (params.status) q.append('status', params.status);
    if (params.page) q.append('page', String(params.page));
    if (params.limit) q.append('limit', String(params.limit));

    return this.request<{
      success: boolean;
      data: UserRbac[];
      meta: { current_page: number; last_page: number; per_page: number; total: number };
    }>(`/users?${q.toString()}`);
  }

  async getUserById(id: string) {
    return this.getUser(id);
  }

  async createUser(data: { username: string; phone_number: string; email: string; full_name: string; password: string; status?: string; roles?: string[] }) {
    return this.request<{ success: boolean; message: string; data: UserRbac }>('/users', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateUser(id: string, data: Partial<UserRbac> & { password?: string; roles?: string[] }) {
    return this.request<{ success: boolean; message: string; data: UserRbac }>(`/users/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteUser(id: string) {
    return this.request<{ success: boolean; message: string }>(`/users/${id}`, {
      method: 'DELETE',
    });
  }

  async getUserRoles(id: string) {
    return this.request<{ success: boolean; data: RoleRbac[] }>(`/users/${id}/roles`);
  }

  async assignUserRoles(id: string, roles: string[], primaryRole?: string) {
    return this.request<{ success: boolean; message: string; data: RoleRbac[] }>(`/users/${id}/roles`, {
      method: 'PUT',
      body: JSON.stringify({ roles, primary_role: primaryRole }),
    });
  }

  async getRoles() {
    return this.request<{ success: boolean; data: RoleRbac[] }>('/roles');
  }

  async getRole(id: string) {
    return this.request<{ success: boolean; data: RoleRbac }>(`/roles/${id}`);
  }

  async createRole(data: { role_code: string; role_name: string; description?: string; permissions?: string[] }) {
    return this.request<{ success: boolean; message: string; data: RoleRbac }>('/roles', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateRole(id: string, data: { role_code?: string; role_name?: string; description?: string; permissions?: string[] }) {
    return this.request<{ success: boolean; message: string; data: RoleRbac }>(`/roles/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteRole(id: string) {
    return this.request<{ success: boolean; message: string }>(`/roles/${id}`, {
      method: 'DELETE',
    });
  }

  async getRolePermissions(id: string) {
    return this.request<{ success: boolean; data: { role_id: string; role_code: string; role_name: string; permissions: PermissionRbac[] } }>(`/roles/${id}/permissions`);
  }

  async syncRolePermissions(id: string, permissions: string[]) {
    return this.request<{ success: boolean; message: string; data: PermissionRbac[] }>(`/roles/${id}/permissions`, {
      method: 'PUT',
      body: JSON.stringify({ permissions }),
    });
  }

  async getPermissions(params: { grouped?: boolean; search?: string; module?: string } = {}) {
    const q = new URLSearchParams();
    if (params.grouped !== undefined) q.append('grouped', params.grouped ? '1' : '0');
    if (params.search) q.append('search', params.search);
    if (params.module) q.append('module', params.module);

    return this.request<{ success: boolean; data: Record<string, PermissionRbac[]> | PermissionRbac[]; total: number }>(`/permissions?${q.toString()}`);
  }

  async createPermission(data: { module: string; permission_code: string; permission_name: string; description?: string }) {
    return this.request<{ success: boolean; message: string; data: PermissionRbac }>('/permissions', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updatePermission(id: string, data: { module?: string; permission_code?: string; permission_name?: string; description?: string }) {
    return this.request<{ success: boolean; message: string; data: PermissionRbac }>(`/permissions/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deletePermission(id: string) {
    return this.request<{ success: boolean; message: string }>(`/permissions/${id}`, {
      method: 'DELETE',
    });
  }

  // ================= QUẢN LÝ CHỦ HỘ & NHÂN KHẨU CĂN HỘ (ADMIN) =================
  async getResidents(params: {
    search?: string;
    apartment_id?: string;
    resident_type?: string;
    is_active?: boolean | string;
    is_head_of_household?: boolean | string;
    sort_by?: string;
    sort_order?: 'asc' | 'desc';
    page?: number;
    limit?: number;
  } = {}, options: RequestInit = {}) {
    const q = new URLSearchParams();
    if (params.search) q.append('search', params.search);
    if (params.apartment_id) q.append('apartment_id', params.apartment_id);
    if (params.resident_type) q.append('resident_type', params.resident_type);
    if (params.is_active !== undefined && params.is_active !== '') q.append('is_active', String(params.is_active));
    if (params.is_head_of_household !== undefined && params.is_head_of_household !== '') q.append('is_head_of_household', String(params.is_head_of_household));
    if (params.sort_by) q.append('sort_by', params.sort_by);
    if (params.sort_order) q.append('sort_order', params.sort_order);
    if (params.page) q.append('page', String(params.page));
    if (params.limit) q.append('limit', String(params.limit));

    return this.request<{
      success: boolean;
      data: ResidentItem[];
      meta: { current_page: number; last_page: number; per_page: number; total: number };
    }>(`/residents?${q.toString()}`, options);
  }

  async getResident(id: string) {
    return this.request<{
      success: boolean;
      data: ResidentDetailResponse;
    }>(`/residents/${id}`);
  }

  async createResident(data: {
    apartment_id: string;
    user_id: string;
    resident_type: string;
    is_head_of_household: boolean;
    stay_start_date: string;
    stay_end_date?: string | null;
    relationship_to_head?: string;
    occupation?: string | null;
    is_active?: boolean;
  }) {
    return this.request<{
      success: boolean;
      message: string;
      data: ResidentItem;
    }>('/residents', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateResident(id: string, data: Partial<{
    resident_type: string;
    is_head_of_household: boolean;
    stay_start_date: string;
    stay_end_date: string | null;
    relationship_to_head: string;
    occupation: string | null;
    is_active: boolean;
    updated_at?: string;
  }>) {
    return this.request<{
      success: boolean;
      message: string;
      data: ResidentItem;
    }>(`/residents/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteResident(id: string) {
    return this.request<{
      success: boolean;
      message: string;
    }>(`/residents/${id}`, {
      method: 'DELETE',
    });
  }

  async getApartmentsForFilter() {
    return this.request<{
      success: boolean;
      data: ApartmentSummaryItem[];
    }>('/meta/apartments');
  }

  // ==========================================
  // TEMPORARY REGISTRATIONS APIs
  // ==========================================
  async getTemporaryRegistrations(params?: Record<string, string | number | boolean | undefined | null>, options: RequestInit = {}) {
    const query = new URLSearchParams();
    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          query.append(key, String(value));
        }
      });
    }
    const qStr = query.toString();
    return this.request<{
      success: boolean;
      data: TemporaryRegistrationItem[];
      meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
      };
    }>(`/residents/temporary-registrations${qStr ? `?${qStr}` : ''}`, options);
  }

  async getTemporaryRegistrationById(id: string) {
    return this.request<{
      success: boolean;
      data: TemporaryRegistrationItem;
    }>(`/residents/temporary-registrations/${id}`);
  }

  async createTemporaryRegistration(data: {
    resident_id: string;
    apartment_id: string;
    registration_type: 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE';
    start_date: string;
    end_date: string;
    reason: string;
    identity_card_front_url?: string | null;
    identity_card_back_url?: string | null;
    notes?: string | null;
  }) {
    return this.request<{
      success: boolean;
      message: string;
      data: TemporaryRegistrationItem;
    }>('/residents/temporary-registrations', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async updateTemporaryRegistration(id: string, data: Partial<{
    registration_type: 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE';
    start_date: string;
    end_date: string;
    reason: string;
    identity_card_front_url?: string | null;
    identity_card_back_url?: string | null;
    notes?: string | null;
    police_reference_code?: string | null;
    updated_at?: string;
  }>) {
    return this.request<{
      success: boolean;
      message: string;
      data: TemporaryRegistrationItem;
    }>(`/residents/temporary-registrations/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  }

  async deleteTemporaryRegistration(id: string) {
    return this.request<{
      success: boolean;
      message: string;
    }>(`/residents/temporary-registrations/${id}`, {
      method: 'DELETE',
    });
  }

  async approveTemporaryRegistration(id: string, data?: { notes?: string; police_reference_code?: string }) {
    return this.request<{
      success: boolean;
      message: string;
      data: TemporaryRegistrationItem;
    }>(`/residents/temporary-registrations/${id}/approve`, {
      method: 'POST',
      body: JSON.stringify(data || {}),
    });
  }

  async rejectTemporaryRegistration(id: string, reason: string) {
    return this.request<{
      success: boolean;
      message: string;
      data: TemporaryRegistrationItem;
    }>(`/residents/temporary-registrations/${id}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    });
  }

  async submitTemporaryRegistrationToPolice(id: string, data?: { police_reference_code?: string; notes?: string }) {
    return this.request<{
      success: boolean;
      message: string;
      data: TemporaryRegistrationItem;
    }>(`/residents/temporary-registrations/${id}/submit-police`, {
      method: 'POST',
      body: JSON.stringify(data || {}),
    });
  }

  async uploadTemporaryRegistrationCccd(file: File, side: 'front' | 'back' = 'front') {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('side', side);

    const headers: Record<string, string> = {
      'Accept': 'application/json',
    };
    const token = this.getAuthToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE_URL}/residents/temporary-registrations/upload-cccd`, {
      method: 'POST',
      headers,
      body: formData,
    });

    if (!response.ok) {
      const errorJson = await response.json().catch(() => null);
      throw new Error(errorJson?.message || 'Tải ảnh CCCD thất bại.');
    }

    return response.json() as Promise<{ success: boolean; message: string; url: string }>;
  }

  async exportTemporaryRegistrationForm(id: string) {
    return this.request<{
      success: boolean;
      data: TemporaryRegistrationExportData;
    }>(`/residents/temporary-registrations/${id}/export`);
  }

  getTemporaryRegistrationDownloadUrl(id: string) {
    return `${API_BASE_URL}/residents/temporary-registrations/${id}/download`;
  }

  // ================= CẤP PHÁT TÀI KHOẢN TỰ ĐỘNG =================
  async getProvisionedAccounts(params: { search?: string; status?: string; limit?: number; page?: number } = {}) {
    const query = new URLSearchParams();
    if (params.search) query.append('search', params.search);
    if (params.status) query.append('status', params.status);
    if (params.limit) query.append('limit', String(params.limit));
    if (params.page) query.append('page', String(params.page));

    return this.request<{
      success: boolean;
      data: any[];
      meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
      };
    }>(`/account-provisioning?${query.toString()}`);
  }

  async provisionAccount(payload: {
    full_name: string;
    email: string;
    phone_number: string;
    national_id_number?: string | null;
    gender?: string;
    date_of_birth?: string | null;
    roles?: string[];
  }) {
    return this.request<{
      success: boolean;
      message: string;
      data: any;
      activation: any;
    }>('/account-provisioning', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async resendAccountActivation(userId: string) {
    return this.request<{
      success: boolean;
      message: string;
      data: any;
    }>(`/account-provisioning/${userId}/resend-activation`, {
      method: 'POST',
    });
  }

  async activateAccountWithToken(payload: {
    email: string;
    token: string;
    password: string;
    password_confirmation: string;
  }) {
    return this.request<{
      success: boolean;
      message: string;
      data: any;
    }>('/account-provisioning/activate', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  // ================= RESIDENT TICKETS =================
  async getResidentTicketCategories() {
    return this.request<{ success: boolean; data: TicketCategoryItem[] }>('/resident/tickets/categories');
  }

  async getManagedTickets(params: URLSearchParams, signal?: AbortSignal) {
    return this.request<{ data: ManagedTicket[]; current_page: number; last_page: number; total: number }>(`/admin/tickets?${params}`, { signal });
  }
  async getTicketOptions() { return this.request<TicketOptions>('/admin/tickets/options'); }
  async getManagedTicket(id: string) { return this.request<ManagedTicket>(`/admin/tickets/${id}`); }
  async createManagedTicket(payload: Record<string, unknown>) { return this.request<ManagedTicket>('/admin/tickets', { method: 'POST', body: JSON.stringify(payload) }); }
  async updateManagedTicket(id: string, payload: Record<string, unknown>) { return this.request<ManagedTicket>(`/admin/tickets/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }); }
  async archiveTicket(id: string, version: number, reason: string) { return this.request<{ success: boolean }>(`/admin/tickets/${id}`, { method: 'DELETE', body: JSON.stringify({ version, reason }) }); }
  async getTicketTechnicians(id: string) { return this.request<{ id: string; full_name: string }[]>(`/admin/tickets/${id}/technicians`); }
  async removeManagedTicketImage(id: string, attachmentId: string, version: number) { return this.request<ManagedTicket>(`/admin/tickets/${id}/attachments/${attachmentId}`, { method: 'DELETE', body: JSON.stringify({ version }) }); }
  async performTicketAction(id: string, payload: Record<string, unknown>) { return this.request<ManagedTicket>(`/admin/tickets/${id}/workflow`, { method: 'POST', body: JSON.stringify(payload) }); }
  async getTicketNotifications(page = 1, signal?: AbortSignal) { return this.request<{ items: TicketNotification[]; unread_count: number; page: number; total_pages: number }>(`/ticket-notifications?page=${page}`, { signal }); }
  async getManagerNotifications(page: number, category: string, unread: boolean, signal?: AbortSignal) {
    const query = new URLSearchParams({ page: String(page), unread: unread ? '1' : '0' });
    if (category) query.set('category', category);
    return this.request<{ items: ManagerNotification[]; unread_count: number; latest_id: string | null; page: number; total_pages: number }>(`/manager/notifications?${query}`, { signal, cache: 'no-store' });
  }
  async readManagerNotification(id: string) { return this.request<{ unread_count: number }>(`/manager/notifications/${encodeURIComponent(id)}/read`, { method: 'POST', cache: 'no-store' }); }
  async readTicketNotification(id: string) { return this.request(`/ticket-notifications/${id}/read`, { method: 'POST' }); }

  async ticketImage(path: string, signal?: AbortSignal): Promise<Blob> {
    if (!path.startsWith('/api/v1/resident/tickets/')) throw new Error('Đường dẫn ảnh không hợp lệ.');
    const url = `${API_BASE_URL}${path.slice('/api/v1'.length)}`;
    const response = await fetch(url, { signal, headers: { Authorization: `Bearer ${this.getToken() || ''}` } });
    if (!response.ok) throw new Error('Không tải được ảnh sự cố.');
    return response.blob();
  }

  async addTicketImages(id: string, files: File[], version: number) {
    const body = new FormData();
    files.forEach((file) => body.append('attachments[]', file));
    body.append('version', String(version));
    return this.request<{ success: boolean; data: TicketItem }>(`/resident/tickets/${id}/attachments`, { method: 'POST', body });
  }

  async removeTicketImage(id: string, attachmentId: string, version: number) {
    return this.request<{ success: boolean; data: TicketItem }>(`/resident/tickets/${id}/attachments/${attachmentId}`, { method: 'DELETE', body: JSON.stringify({ version }) });
  }

  async getResidentTickets(filters?: {
    status?: string;
    category_id?: string;
    priority?: string;
    search?: string;
    page?: number;
    per_page?: number;
  }, signal?: AbortSignal) {
    const params = new URLSearchParams();
    if (filters?.status && filters.status !== 'ALL') params.append('status', filters.status);
    if (filters?.category_id && filters.category_id !== 'ALL') params.append('category_id', filters.category_id);
    if (filters?.priority && filters.priority !== 'ALL') params.append('priority', filters.priority);
    if (filters?.search) params.append('search', filters.search);
    if (filters?.page) params.append('page', String(filters.page));
    if (filters?.per_page) params.append('per_page', String(filters.per_page));

    const query = params.toString() ? `?${params.toString()}` : '';
    return this.request<TicketListResponse>(`/resident/tickets${query}`, { signal });
  }

  async getResidentTicketDetail(id: string) {
    return this.request<{ success: boolean; data: TicketItem }>(`/resident/tickets/${id}`);
  }

  async createResidentTicket(payload: FormData | Record<string, any>, idempotencyKey?: string) {
    const headers: Record<string, string> = {};
    if (idempotencyKey) {
      headers['X-Idempotency-Key'] = idempotencyKey;
    }
    const isFormData = payload instanceof FormData;
    return this.request<{ success: boolean; message: string; data: TicketItem }>('/resident/tickets', {
      method: 'POST',
      body: isFormData ? payload : JSON.stringify(payload),
      headers,
    });
  }

  async updateResidentTicket(id: string, payload: {
    title: string;
    description: string;
    location: string;
    priority?: string;
    preferred_service_time?: string | null;
    version: number;
    updated_at?: string;
  }) {
    return this.request<{ success: boolean; message: string; data: TicketItem }>(`/resident/tickets/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    });
  }

  async cancelResidentTicket(id: string, version: number, reason: string) {
    return this.request<{ success: boolean; message: string; data: TicketItem }>(`/resident/tickets/${id}/cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason, version }),
    });
  }

  async confirmResidentTicket(id: string, version: number, note?: string) {
    return this.request<{ success: boolean; message: string; data: TicketItem }>(`/resident/tickets/${id}/confirm`, {
      method: 'POST',
      body: JSON.stringify({ note, version }),
    });
  }

  async reopenResidentTicket(id: string, version: number, reason: string) {
    return this.request<{ success: boolean; message: string; data: TicketItem }>(`/resident/tickets/${id}/reopen`, {
      method: 'POST',
      body: JSON.stringify({ reason, version }),
    });
  }

  async getResidentTicketHistory(id: string) {
    return this.request<{ success: boolean; data: TicketHistoryItem[] }>(`/resident/tickets/${id}/history`);
  }

  async getTicketComments(id: string, filters: { cursor?: string; since?: string } = {}, signal?: AbortSignal) {
    const params = new URLSearchParams(filters);
    return this.request<TicketCommentPage>(`/tickets/${id}/comments?${params}`, { signal, cache: 'no-store' });
  }

  async postTicketComment(id: string, payload: TicketCommentPayload, signal?: AbortSignal) {
    return this.request<{ data: TicketComment }>(`/tickets/${id}/comments`, { method: 'POST', body: JSON.stringify(payload), signal, cache: 'no-store' });
  }

  async getTicketDiscussionHistory(id: string, cursor?: string, signal?: AbortSignal) {
    return this.request<TicketDiscussionPage<TicketHistoryItem>>(`/tickets/${id}/history${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`, { signal, cache: 'no-store' });
  }
  async getResidentAnnouncements(params: URLSearchParams, signal?: AbortSignal) { return this.request<ResidentAnnouncementPage>(`/resident/announcements?${params}`, { signal, cache: 'no-store' }); }
  async getResidentAnnouncement(id: string, signal?: AbortSignal) { return this.request<ResidentAnnouncement>(`/resident/announcements/${encodeURIComponent(id)}`, { signal, cache: 'no-store' }); }
  async readResidentAnnouncement(id: string, version: number, signal?: AbortSignal) { return this.request<ResidentAnnouncement>(`/resident/announcements/${encodeURIComponent(id)}/read`, { method: 'POST', body: JSON.stringify({ version }), signal, cache: 'no-store' }); }
  async acknowledgeResidentAnnouncement(id: string, version: number, signal?: AbortSignal) { return this.request<ResidentAnnouncement>(`/resident/announcements/${encodeURIComponent(id)}/acknowledge`, { method: 'POST', body: JSON.stringify({ version }), signal, cache: 'no-store' }); }

  async getAnnouncements(params: URLSearchParams, signal?: AbortSignal) { return this.request<AnnouncementPage>(`/admin/announcements?${params}`, { signal, cache: 'no-store' }); }
  async getAnnouncementOptions(signal?: AbortSignal) { return this.request<AnnouncementOptions>('/admin/announcements/options', { signal, cache: 'no-store' }); }
  async getAnnouncement(id: string, signal?: AbortSignal) { return this.request<Announcement>(`/admin/announcements/${id}`, { signal, cache: 'no-store' }); }
  async saveAnnouncement(id: string | null, body: FormData) { if (id) body.set('_method', 'PATCH'); return this.request<Announcement>(`/admin/announcements${id ? `/${id}` : ''}`, { method: 'POST', body, cache: 'no-store' }); }
  async previewAnnouncement(content_html: string) { return this.request<{ content_html: string }>('/admin/announcements/preview', { method: 'POST', body: JSON.stringify({ content_html }), cache: 'no-store' }); }
  async announcementAction(id: string, action: 'publish' | 'unpublish' | 'archive', payload: { version: number; reason?: string; publish_at?: string | null }) { return this.request<Announcement>(`/admin/announcements/${id}${action === 'archive' ? '' : `/${action}`}`, { method: action === 'archive' ? 'DELETE' : 'POST', body: JSON.stringify(payload), cache: 'no-store' }); }
  async uploadAnnouncementFile(id: string, file: File): Promise<AnnouncementUpload> {
    const ticket = await this.request<AnnouncementUpload>('/admin/announcements/uploads', { method: 'POST', body: JSON.stringify({ announcement_id: id, file_name: file.name, size: file.size, mime_type: file.type }) });
    const local = ticket.transport === 'local';
    if (local ? !ticket.url.startsWith('/api/v1/admin/announcements/uploads/') : !ticket.url.startsWith('https://')) throw new Error('Địa chỉ upload không hợp lệ.');
    const url = local ? `${API_BASE_URL}/admin/announcements/uploads/${encodeURIComponent(ticket.token)}` : ticket.url;
    try {
      const response = await fetch(url, { method: 'PUT', body: file, credentials: 'omit', headers: { ...ticket.headers, ...(local ? { Authorization: `Bearer ${this.getToken() || ''}` } : {}) } });
      if (!response.ok) throw new Error('Upload chưa hoàn tất. Vui lòng thử lại; bản nhập vẫn được giữ.');
      return ticket;
    } catch (error) { void this.discardAnnouncementUpload(ticket.token).catch(() => {}); throw error; }
  }
  async discardAnnouncementUpload(token: string) { return this.request('/admin/announcements/uploads/discard', { method: 'POST', body: JSON.stringify({ token }) }); }
  async announcementAttachment(id: string, attachmentId: string, audience: 'admin' | 'resident' = 'admin', signal?: AbortSignal): Promise<Blob> {
    const response = await fetch(`${API_BASE_URL}/${audience}/announcements/${encodeURIComponent(id)}/attachments/${encodeURIComponent(attachmentId)}`, { signal, cache: 'no-store', headers: { Authorization: `Bearer ${this.getToken() || ''}` } });
    if (!response.ok) throw new Error('Không tải được tệp. Kiểm tra phiên đăng nhập và quyền xem bài.');
    if (response.headers.get('content-type')?.includes('application/json')) {
      const result: { download_url: string } = await response.json();
      if (!result.download_url.startsWith('https://')) throw new Error('Địa chỉ tải không hợp lệ.');
      const download = await fetch(result.download_url, { signal, credentials: 'omit', cache: 'no-store' });
      if (!download.ok) throw new Error('Không tải được tệp từ kho riêng tư.');
      return download.blob();
    }
    return response.blob();
  }

  // ================= F12: HÒM THƯ GÓP Ý & PHÂN TÍCH CẢM XÚC =================
  async getResidentSuggestionBoxes(): Promise<{ success: boolean; data: SuggestionBoxItem[] }> {
    return this.request('/resident/suggestion-boxes');
  }

  async submitResidentSuggestion(formData: FormData): Promise<{ success: boolean; message: string; data: SuggestionItem; anonymous_token?: string }> {
    return this.request('/resident/suggestions', {
      method: 'POST',
      body: formData,
    });
  }

  async getResidentSuggestions(params?: Record<string, any>): Promise<ResidentSuggestionListResponse> {
    const query = params ? `?${new URLSearchParams(params).toString()}` : '';
    return this.request(`/resident/suggestions/my-suggestions${query}`);
  }

  async lookupAnonymousSuggestion(token: string): Promise<{ success: boolean; data: AnonymousSuggestionDetail }> {
    return this.request(`/resident/suggestions/anonymous-lookup?token=${encodeURIComponent(token)}`);
  }

  async getManagementSuggestions(params?: Record<string, any>): Promise<ManagementSuggestionListResponse> {
    const query = params ? `?${new URLSearchParams(params).toString()}` : '';
    return this.request(`/management/suggestions${query}`);
  }

  async getManagementSuggestionDetail(id: string): Promise<{ success: boolean; data: SuggestionItem }> {
    return this.request(`/management/suggestions/${id}`);
  }

  async updateSuggestionStatus(id: string, payload: { status: string; version: number; rejection_reason?: string }): Promise<{ success: boolean; message: string; data: SuggestionItem }> {
    return this.request(`/management/suggestions/${id}/status`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  }

  async replySuggestion(id: string, payload: { official_response_text: string; version: number; status?: string }): Promise<{ success: boolean; message: string; data: SuggestionItem }> {
    return this.request(`/management/suggestions/${id}/reply`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  }

  async analyzeSuggestionSentiment(suggestionId: string): Promise<{ success: boolean; status: string; message: string; data: SuggestionItem }> {
    return this.request('/suggestions/ai-sentiment-analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ suggestion_id: suggestionId }),
    });
  }

  async createSuggestionBox(payload: { box_name: string; block_id?: string | null; description?: string; is_open?: boolean }): Promise<{ success: boolean; message: string; data: SuggestionBoxItem }> {
    return this.request('/management/suggestion-boxes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  }

  // ================= F13: KHAO SAT & THONG KE CSAT =================
  async getResidentActiveSurveys(): Promise<{ status: string; data: ResidentSurveyItem[] }> {
    return this.request('/resident/surveys/active');
  }

  async getResidentSurveyDetails(id: string): Promise<{ status: string; data: ResidentSurveyDetailResponse }> {
    return this.request(`/resident/surveys/${id}`);
  }

  async submitResidentSurvey(id: string, answers: Record<string, any>, idempotencyKey?: string): Promise<{ status: string; message: string; data: { response_id: string; submitted_at: string } }> {
    return this.request(`/resident/surveys/${id}/submit`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ answers, idempotency_key: idempotencyKey }),
    });
  }

  async getManagementSurveys(params?: Record<string, any>): Promise<ManagementSurveyListResponse> {
    const query = new URLSearchParams();
    if (params) {
      Object.entries(params).forEach(([key, val]) => {
        if (val !== undefined && val !== null && val !== '') {
          query.append(key, String(val));
        }
      });
    }
    const qStr = query.toString();
    return this.request(`/management/surveys${qStr ? `?${qStr}` : ''}`);
  }

  async getManagementSurveyKpis(): Promise<{ status: string; data: SurveyOverviewKpis }> {
    return this.request('/management/surveys/kpis');
  }

  async createManagementSurvey(payload: CreateSurveyPayload): Promise<{ status: string; message: string; data: SatisfactionSurveyItem }> {
    return this.request('/management/surveys', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  }

  async updateManagementSurvey(id: string, payload: UpdateSurveyPayload): Promise<{ status: string; message: string; data: SatisfactionSurveyItem }> {
    return this.request(`/management/surveys/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  }

  async toggleManagementSurveyStatus(id: string, isActive: boolean): Promise<{ status: string; message: string; data: SatisfactionSurveyItem }> {
    return this.request(`/management/surveys/${id}/toggle-status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ is_active: isActive }),
    });
  }

  async getManagementSurveyAnalytics(id: string): Promise<{ status: string; data: SurveyAnalyticsData }> {
    return this.request(`/management/surveys/${id}/analytics`);
  }

}

export interface TicketParticipant { id: string; full_name: string; role: string; }
export interface TicketComment {
  id: string;
  ticket_id: string;
  comment_text: string;
  is_internal_staff_note: boolean;
  created_at: string;
  sender: TicketParticipant | null;
}
export interface TicketCommentPayload { id: string; comment_text: string; is_internal_staff_note: boolean; }
export interface TicketDiscussionPage<T> { items: T[]; next_cursor: string | null; }
export interface TicketCommentPage extends TicketDiscussionPage<TicketComment> {
  meta: { status: TicketItem['status']; ticket_version: number; history_count: number; can_comment: boolean; can_internal_note: boolean; can_view_internal: boolean; };
}

export interface ManagedTicket extends TicketItem {
  actions: Record<string, boolean>;
}
export interface TicketNotification { id: string; title: string; body_message: string; deep_link_url: string; is_read: boolean | number; created_at: string; }
export interface ManagerNotification extends TicketNotification { category: 'TICKET' | 'AMENITY_BOOKING'; }
export interface TicketOptions {
  categories: TicketCategoryItem[];
  blocks: { id: string; block_name: string }[];
  apartments: { id: string; block_id: string; apartment_number: string }[];
  residents: { id: string; apartment_id: string; full_name: string }[];
  can_create: boolean;
}

export interface TicketCategoryItem {
  id: string;
  category_code: string;
  category_name: string;
  default_priority?: string;
  sla_response_time_minutes?: number;
  sla_resolution_time_hours?: number;
}

export interface TicketAttachmentItem {
  id: string;
  ticket_id: string;
  file_name: string;
  file_url: string;
  storage_path?: string;
  file_type: string;
  is_resolution_proof?: boolean;
  mime_type?: string;
  file_size_bytes?: number;
  created_at: string;
}
export interface TicketHistoryItem {
  id: string;
  ticket_id: string;
  action_type: string;
  old_status?: string | null;
  new_status?: string | null;
  notes?: string | null;
  created_at: string;
  performer?: {
    id: string;
    full_name: string;
    email?: string;
    role?: string;
  } | null;
}

export interface TicketItem {
  id: string;
  ticket_number: string;
  category_id: string;
  apartment_id: string;
  creator_user_id: string;
  title: string;
  description: string;
  location: string;
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
  status: 'NEW' | 'RECEIVED' | 'IN_PROGRESS' | 'RESOLVED' | 'CLOSED' | 'CANCELLED';
  current_technician_user_id?: string | null;
  preferred_service_time?: string | null;
  sla_deadline?: string | null;
  resolved_at?: string | null;
  closed_at?: string | null;
  version: number;
  created_at: string;
  updated_at: string;
  category?: TicketCategoryItem;
  apartment?: {
    id: string;
    apartment_number: string;
    block?: {
      id: string;
      block_name: string;
      block_code: string;
    };
  };
  creator?: {
    id: string;
    full_name: string;
    email: string;
    phone_number?: string;
  };
  technician?: {
    id: string;
    full_name: string;
    email: string;
    phone_number?: string;
  } | null;
  attachments?: TicketAttachmentItem[];
  history?: TicketHistoryItem[];
}

export interface TicketListResponse {
  success: boolean;
  data: TicketItem[];
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  counters: {
    all: number;
    new: number;
    received?: number;
    in_progress: number;
    resolved: number;
    closed: number;
    cancelled: number;
  };
}

export interface TemporaryRegistrationItem {
  id: string;
  resident_id: string;
  apartment_id: string;
  registration_type: 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE';
  start_date: string;
  end_date: string;
  reason: string;
  police_status: 'PENDING_POLICE_SUBMISSION' | 'SUBMITTED_TO_POLICE' | 'APPROVED' | 'REJECTED' | string;
  police_reference_code?: string | null;
  identity_card_front_url?: string | null;
  identity_card_back_url?: string | null;
  reviewed_by?: string | null;
  reviewed_at?: string | null;
  notes?: string | null;
  created_at: string;
  updated_at: string;
  resident?: {
    id: string;
    user_id: string;
    apartment_id: string;
    resident_type: string;
    is_head_of_household: boolean;
    relationship_to_head?: string | null;
    occupation?: string | null;
    user?: {
      id: string;
      username: string;
      full_name: string;
      phone_number: string;
      email: string;
      national_id_number?: string | null;
      avatar_url?: string | null;
      gender?: string | null;
      date_of_birth?: string | null;
      status: string;
    };
  };
  apartment?: {
    id: string;
    apartment_number: string;
    block_id?: string;
    floor_id?: string;
    room_type?: string;
    status: string;
  };
  reviewer?: {
    id: string;
    username: string;
    full_name: string;
    phone_number?: string;
    email: string;
  } | null;
}

export interface TemporaryRegistrationExportData {
  registration: TemporaryRegistrationItem;
  resident: any;
  user: any;
  apartment: any;
  reviewer: any;
  form_code: string;
  form_title: string;
  police_status: string;
  police_reference_code?: string | null;
  html_content: string;
}

export interface ResidentItem {
  id: string;
  user_id: string;
  apartment_id: string;
  resident_type: 'OWNER' | 'TENANT' | 'FAMILY_MEMBER';
  is_head_of_household: boolean;
  stay_start_date: string;
  stay_end_date?: string | null;
  relationship_to_head: 'SELF' | 'SPOUSE' | 'CHILD' | 'PARENT' | 'TENANT' | 'MAID' | string;
  occupation?: string | null;
  vehicle_count: number;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
  deleted_at?: string | null;
  user?: {
    id: string;
    username: string;
    full_name: string;
    phone_number: string;
    email: string;
    national_id_number?: string | null;
    avatar_url?: string | null;
    status: string;
    gender?: string | null;
    date_of_birth?: string | null;
  };
  apartment?: {
    id: string;
    apartment_number: string;
    block_id?: string;
    floor_id?: string;
    status?: string;
    room_type?: string;
    gross_floor_area_sqm?: number;
  };
}

export interface ResidentDetailResponse {
  resident: ResidentItem;
  apartment: {
    id: string;
    apartment_number: string;
    block_id?: string;
    floor_id?: string;
    status?: string;
    room_type?: string;
    gross_floor_area_sqm?: number;
  };
  head_of_household?: ResidentItem | null;
  household_members: ResidentItem[];
  total_members: number;
}

export interface ApartmentSummaryItem {
  id: string;
  apartment_number: string;
  block_id?: string;
  status: string;
  residents_count: number;
  head_of_household?: {
    id: string;
    user?: {
      id: string;
      full_name: string;
      phone_number: string;
    };
  } | null;
}

export interface UserRbac {
  id: string;
  username: string;
  email: string;
  phone_number: string;
  full_name: string;
  status: 'ACTIVE' | 'LOCKED' | 'SUSPENDED';
  roles: RoleRbac[];
  permissions?: string[];
  created_at?: string;
}

export interface RoleRbac {
  id: string;
  role_code: string;
  role_name: string;
  description?: string | null;
  is_system_role: boolean;
  users_count?: number;
  permissions_count?: number;
  permissions?: PermissionRbac[];
}

export interface PermissionRbac {
  id: string;
  module: string;
  permission_code: string;
  permission_name: string;
  description?: string | null;
  created_at?: string;
}

export const api = new ApiService();
export default api;

export type AnnouncementStatus = 'DRAFT' | 'PUBLISHED' | 'UNPUBLISHED' | 'SCHEDULED' | 'EXPIRED';
export interface AnnouncementAttachment { id: string; file_name: string; mime_type: string; file_size_bytes: number; }
export interface Announcement {
  requires_acknowledgement: boolean;
  id: string; title: string; summary_excerpt: string | null; content_html: string; category: string;
  target_scope: 'ALL_BLOCKS' | 'BLOCK' | 'FLOOR'; target_block_id: string | null; target_floor_id: string | null;
  is_pinned: boolean; is_urgent: boolean; publish_at: string; expires_at: string | null; author_name?: string;
  status: 'DRAFT' | 'PUBLISHED' | 'UNPUBLISHED'; display_status: AnnouncementStatus; version: number; created_at: string;
  actions: { edit: boolean; publish: boolean; archive: boolean }; attachments?: AnnouncementAttachment[];
}
export interface AnnouncementPage { data: Announcement[]; current_page: number; last_page: number; total: number; }
export interface AnnouncementOptions {
  timezone: string; categories: Record<string, string>; blocks: { id: string; block_name: string }[];
  floors: { id: string; block_id: string; floor_name: string }[]; can_target_all: boolean; can_create: boolean; can_publish: boolean;
}

export interface AnnouncementUpload { token: string; transport: 'local' | 's3'; url: string; headers: Record<string, string>; expires_at: number; }

export interface ResidentAnnouncement {
  id: string; title: string; summary_excerpt: string | null; category: string;
  target_scope: 'ALL_BLOCKS' | 'BLOCK' | 'FLOOR'; block_name: string | null; floor_name: string | null;
  is_pinned: boolean; is_urgent: boolean; requires_acknowledgement: boolean; version: number;
  publish_at: string; expires_at: string | null; read_at: string | null; acknowledged_at: string | null;
  content_html?: string; attachments?: AnnouncementAttachment[];
}
export interface ResidentAnnouncementPage {
  data: ResidentAnnouncement[]; current_page: number; last_page: number; total: number;
  unread_count: number; categories: Record<string, string>; timezone: string;
}

// ================= F12 SUGGESTION INTERFACES =================
export interface SuggestionBoxItem {
  id: string;
  box_name: string;
  block_id: string | null;
  description: string | null;
  is_open: boolean;
  created_at?: string;
  block?: {
    id: string;
    block_name: string;
    block_code: string;
  } | null;
}

export interface SuggestionAttachmentItem {
  id: string;
  file_name: string;
  storage_disk: string;
  storage_path: string;
  mime_type: string;
  file_size_bytes: number;
  url: string;
}

export interface SuggestionItem {
  id: string;
  box_id: string;
  idempotency_key?: string | null;
  resident_user_id?: string | null;
  apartment_id?: string | null;
  is_anonymous: boolean;
  topic_category: 'AN_NINH' | 'VE_SINH' | 'BAI_XE' | 'TIEN_ICH' | 'THAI_DO_NHAN_VIEN' | 'KHAC' | string;
  title: string;
  content_text: string;
  attached_files?: SuggestionAttachmentItem[];
  status: 'RECEIVED' | 'REVIEWING' | 'ACCEPTED' | 'RESOLVED' | 'REJECTED' | string;
  version: number;
  official_response_text?: string | null;
  rejection_reason?: string | null;
  responded_by_staff_id?: string | null;
  responded_at?: string | null;
  ai_sentiment: 'POSITIVE' | 'NEUTRAL' | 'NEGATIVE' | 'VERY_ANGRY' | 'NOT_ANALYZED' | string;
  ai_sentiment_score?: number | null;
  created_at: string;
  updated_at: string;
  box?: SuggestionBoxItem;
  resident_user?: {
    id: string;
    full_name: string;
    phone_number?: string;
  } | null;
  apartment?: {
    id: string;
    apartment_number: string;
    block_id: string;
  } | null;
  responded_by_staff?: {
    id: string;
    full_name: string;
  } | null;
}

export interface SuggestionKpis {
  total: number;
  received: number;
  reviewing: number;
  accepted: number;
  resolved: number;
  rejected: number;
  negative_sentiment: number;
}

export interface ManagementSuggestionListResponse {
  success: boolean;
  data: SuggestionItem[];
  kpis: SuggestionKpis;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface ResidentSuggestionListResponse {
  success: boolean;
  data: SuggestionItem[];
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface AnonymousSuggestionDetail {
  id: string;
  box_name?: string;
  topic_category: string;
  title: string;
  content_text: string;
  status: string;
  attached_files?: SuggestionAttachmentItem[];
  official_response_text?: string | null;
  rejection_reason?: string | null;
  responded_at?: string | null;
  created_at: string;
}

// ================= F13 TYPES: KHAO SAT CSAT =================
export interface SurveyQuestionItem {
  id: string;
  question_order: number;
  question_text: string;
  question_type: 'RATING_1_TO_5' | 'YES_NO' | 'MULTIPLE_CHOICE' | 'FREE_TEXT';
  options?: string[];
  options_json?: string[];
  is_required: boolean;
}

export interface ResidentSurveyItem {
  id: string;
  survey_code: string;
  title: string;
  description?: string;
  target_block_name: string;
  start_date: string;
  end_date: string;
  days_left: number;
  total_questions: number;
  has_submitted: boolean;
  is_open: boolean;
}

export interface ResidentSurveyDetailResponse {
  survey: {
    id: string;
    survey_code: string;
    title: string;
    description?: string;
    target_block_name: string;
    start_date: string;
    end_date: string;
    is_open: boolean;
    has_submitted: boolean;
    submitted_at?: string | null;
  };
  questions: SurveyQuestionItem[];
}

export interface SatisfactionSurveyItem {
  id: string;
  survey_code: string;
  title: string;
  description?: string | null;
  target_block_id?: string | null;
  target_block?: { id: string; block_name: string } | null;
  start_date: string;
  end_date: string;
  is_active: boolean;
  computed_status: 'ACTIVE' | 'UPCOMING' | 'CLOSED' | 'DISABLED';
  version: number;
  created_at: string;
  questions_count?: number;
  responses_count?: number;
  eligible_count?: number;
  participation_rate_percent?: number;
  questions?: SurveyQuestionItem[];
}

export interface SurveyOverviewKpis {
  total_surveys: number;
  active_surveys: number;
  total_responses: number;
  overall_average_rating: number;
  overall_csat_percentage: number;
  total_valid_ratings: number;
  dissatisfied_count: number;
}

export interface QuestionRatingDistribution {
  stars: number;
  count: number;
  percent: number;
}

export interface QuestionOptionDistribution {
  option: string;
  count: number;
  percent: number;
}

export interface QuestionAnalyticsItem {
  question_id: string;
  question_order: number;
  question_text: string;
  question_type: 'RATING_1_TO_5' | 'YES_NO' | 'MULTIPLE_CHOICE' | 'FREE_TEXT';
  is_required: boolean;
  total_answers: number;
  average_rating?: number;
  csat_percentage?: number;
  satisfied_count?: number;
  neutral_count?: number;
  dissatisfied_count?: number;
  rating_distribution?: QuestionRatingDistribution[];
  yes_count?: number;
  no_count?: number;
  yes_percentage?: number;
  no_percentage?: number;
  options_distribution?: QuestionOptionDistribution[];
  text_responses?: Array<{ id: string; answer: string; created_at: string }>;
  notice?: string;
}

export interface SurveyAnalyticsData {
  survey: {
    id: string;
    survey_code: string;
    title: string;
    description?: string;
    target_block_id?: string | null;
    target_block_name: string;
    start_date: string;
    end_date: string;
    is_active: boolean;
    computed_status: string;
    version: number;
  };
  kpis: {
    total_responses: number;
    eligible_apartments_count: number;
    participation_rate_percent: number;
    overall_average_rating: number;
    overall_csat_percentage: number;
    total_valid_ratings: number;
    satisfied_ratings_count: number;
    neutral_ratings_count: number;
    dissatisfied_ratings_count: number;
  };
  question_stats: QuestionAnalyticsItem[];
}

export interface CreateSurveyPayload {
  survey_code?: string;
  title: string;
  description?: string;
  target_block_id?: string | null;
  start_date: string;
  end_date: string;
  is_active?: boolean;
  questions: Array<{
    question_text: string;
    question_type: 'RATING_1_TO_5' | 'YES_NO' | 'MULTIPLE_CHOICE' | 'FREE_TEXT';
    options_json?: string[];
    is_required?: boolean;
    question_order?: number;
  }>;
}

export interface UpdateSurveyPayload {
  version: number;
  title?: string;
  description?: string;
  target_block_id?: string | null;
  start_date?: string;
  end_date?: string;
  is_active?: boolean;
  questions?: Array<{
    question_text: string;
    question_type: 'RATING_1_TO_5' | 'YES_NO' | 'MULTIPLE_CHOICE' | 'FREE_TEXT';
    options_json?: string[];
    is_required?: boolean;
    question_order?: number;
  }>;
}

export interface ManagementSurveyListResponse {
  status: string;
  data: SatisfactionSurveyItem[];
  kpis: SurveyOverviewKpis;
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
