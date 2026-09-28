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
  search_time_ms?: number;
  corrected_query?: string | null;
  is_fuzzy?: boolean;
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
    return localStorage.getItem('smart_cassavas_token');
  }

  public setToken(token: string) {
    localStorage.setItem('smart_cassavas_token', token);
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
    amenityCache.clearAll();
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

  // ================= CATEGORIES =================
  async getCategories(forceRefresh: boolean = false): Promise<Category[]> {
    const key = 'amenities:categories';
    const cached = amenityCache.get<Category[]>(key);

    if (!forceRefresh && cached.exists && cached.data && cached.isFresh) {
      return cached.data;
    }

    return amenityCache.dedupe(key, async () => {
      try {
        const { data, etag, notModified } = await this.requestWithEtag<Category[]>(
          '/admin/amenity-categories',
          cached.etag
        );
        if (notModified && cached.data) {
          amenityCache.touch(key);
          return cached.data;
        }
        if (data) {
          amenityCache.set(key, data, etag, 10 * 60 * 1000); // 10 mins fresh
          return data;
        }
        return cached.data || [];
      } catch (err) {
        if (cached.data) return cached.data;
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

    const cacheKey = `${type}:${trimmed.toLowerCase()}`;
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

  async patchAmenityStatus(id: string, is_active: boolean): Promise<Amenity> {
    const result = await this.request<Amenity>(`/admin/amenities/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active }),
    });
    suggestionCache.clear();
    amenityCache.invalidateAmenities();
    amenityCache.broadcastMutation('AMENITY_STATUS_CHANGED', id);
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
    }
  ): Promise<Blackout> {
    const result = await this.request<Blackout>(`/admin/amenities/${amenityId}/blackouts`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateBlackouts(amenityId);
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
    }
  ): Promise<Blackout> {
    const result = await this.request<Blackout>(`/admin/amenities/${amenityId}/blackouts/${blackoutId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateBlackouts(amenityId);
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
  async getAmenityBookings(amenityId: string, forceRefresh: boolean = false): Promise<AmenityBooking[]> {
    const key = `amenity:${amenityId}:bookings`;
    const lookup = amenityCache.get<AmenityBooking[]>(key);
    if (!forceRefresh && lookup.exists && lookup.data && lookup.isFresh) {
      return lookup.data;
    }

    const bookings = await this.request<AmenityBooking[]>(`/admin/amenities/${amenityId}/bookings`);
    amenityCache.set(key, bookings, null, 2 * 60 * 1000);
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
      body: JSON.stringify({ status, admin_notes: adminNotes }),
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

    if (!forceRefresh && cached.exists && cached.data && cached.isFresh) {
      return cached.data;
    }

    return amenityCache.dedupe(key, async () => {
      try {
        let blocks: BlockOption[];
        try {
          blocks = await this.request<BlockOption[]>('/admin/blocks');
        } catch {
          blocks = await this.request<BlockOption[]>('/meta/blocks');
        }
        amenityCache.set(key, blocks, null, 15 * 60 * 1000); // 15 mins fresh
        return blocks;
      } catch (err) {
        if (cached.data) return cached.data;
        throw err;
      }
    });
  }

  // ================= RESIDENT PORTAL =================
  async getResidentOverview(userId?: string): Promise<any> {
    const query = userId ? `?user_id=${encodeURIComponent(userId)}` : '';
    return this.request<any>(`/resident/overview${query}`);
  }

  async createResidentTicket(payload: {
    title: string;
    description: string;
    category_id?: string;
    priority?: string;
    preferred_service_time?: string;
    apartment_id?: string;
    user_id?: string;
  }): Promise<any> {
    return this.request<any>('/resident/tickets', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async createResidentBooking(payload: {
    amenity_id: string;
    booking_date: string;
    start_time: string;
    end_time: string;
    attendee_count?: number;
    apartment_id?: string;
    user_id?: string;
  }): Promise<any> {
    const result = await this.request<any>('/resident/amenity-bookings', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    amenityCache.invalidateAmenities();
    return result;
  }

  async createResidentVisitor(payload: {
    visitor_name: string;
    visitor_phone?: string;
    visit_purpose?: string;
    expected_arrival_time: string;
    vehicle_license_plate?: string;
    apartment_id?: string;
    user_id?: string;
  }): Promise<any> {
    return this.request<any>('/resident/visitors', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async payResidentInvoice(invoiceId: string): Promise<any> {
    return this.request<any>(`/resident/invoices/${invoiceId}/pay`, {
      method: 'POST',
    });
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
  } = {}) {
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
    }>(`/residents?${q.toString()}`);
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
    is_head_of_household?: boolean;
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
