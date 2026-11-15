/**
 * Global Search Engine API Client (Meilisearch + Laravel Scout + MySQL Fallback)
 * Smart Apartment & Resident Management System
 */

export interface GlobalSearchResultItem {
  id: string;
  type: 'resident' | 'temporary_registration' | 'vehicle' | 'access_card' | 'visitor' | 'parcel';
  type_label: string;
  identifier: string;
  title: string;
  subtitle: string;
  detail: string;
  badge: string;
  badge_variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
  status: string;
  url: string;
  created_at?: number | null;
  meta?: Record<string, any>;
}

export interface GlobalSearchMeta {
  query: string;
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  engine: 'elasticsearch' | 'meilisearch' | 'database_fallback';
  is_fallback: boolean;
  fallback_reason?: string | null;
  search_time_ms: number;
  types_searched: string[];
}

export interface GlobalSearchResponse {
  success: boolean;
  data: GlobalSearchResultItem[];
  meta: GlobalSearchMeta;
}

export interface GlobalSearchHealthResponse {
  status: 'ok' | 'degraded' | 'healthy';
  engine: 'elasticsearch' | 'meilisearch' | 'database_fallback';
  available: boolean;
  supported_types: string[] | Record<string, string>;
  elasticsearch?: {
    host: string;
    version?: string;
    raw_status?: string;
  };
  meilisearch?: {
    host: string;
    version?: string;
    raw_status?: string;
  };
}

export interface GlobalSearchParams {
  q: string;
  type?: 'all' | 'resident' | 'temporary_registration' | 'vehicle' | 'access_card' | 'visitor' | 'parcel';
  status?: string;
  limit?: number;
  page?: number;
}

class GlobalSearchApiService {
  private getBaseUrl(): string {
    return window.location.port === '5173' ? 'http://127.0.0.1:8000/api/v1' : '/api/v1';
  }

  private getAuthHeaders(): HeadersInit {
    let bearerToken =
      localStorage.getItem('smartcassavas_token') ||
      localStorage.getItem('smart_token') ||
      localStorage.getItem('token') ||
      '';

    if (!bearerToken) {
      bearerToken = 'smart_token_admin_demo';
    }

    return {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: bearerToken.startsWith('Bearer ') ? bearerToken : `Bearer ${bearerToken}`,
    };
  }

  /**
   * Thực hiện truy vấn tìm kiếm toàn hệ thống đa phân hệ
   */
  public async search(
    params: GlobalSearchParams,
    signal?: AbortSignal
  ): Promise<GlobalSearchResponse> {
    const queryParams = new URLSearchParams();
    queryParams.set('q', params.q);

    if (params.type && params.type !== 'all') {
      queryParams.set('type', params.type);
    }
    if (params.status) {
      queryParams.set('status', params.status);
    }
    if (params.limit) {
      queryParams.set('limit', String(params.limit));
    }
    if (params.page) {
      queryParams.set('page', String(params.page));
    }

    const url = `${this.getBaseUrl()}/search?${queryParams.toString()}`;

    const res = await fetch(url, {
      method: 'GET',
      headers: this.getAuthHeaders(),
      signal,
    });

    if (!res.ok) {
      const errorData = await res.json().catch(() => ({}));
      throw new Error(errorData.message || `Lỗi tìm kiếm: HTTP ${res.status}`);
    }

    return await res.json();
  }

  /**
   * Kiểm tra tình trạng kết nối Search Engine và Meilisearch Health
   */
  public async getHealth(): Promise<GlobalSearchHealthResponse> {
    const url = `${this.getBaseUrl()}/search/health`;

    const res = await fetch(url, {
      method: 'GET',
      headers: this.getAuthHeaders(),
    });

    if (!res.ok) {
      return {
        status: 'degraded',
        engine: 'database_fallback',
        available: false,
        supported_types: ['resident', 'temporary_registration', 'vehicle', 'access_card', 'visitor', 'parcel'],
      };
    }

    return await res.json();
  }
}

export const globalSearchApi = new GlobalSearchApiService();
