export interface PricingTier {
    id?: string;
    pricing_config_id?: string;
    tier_order: number;
    tier_name: string;
    min_usage_threshold: number;
    max_usage_threshold: number | null;
    unit_price: number;
}

export type BillingType = 'TIERED_USAGE' | 'FIXED_MONTHLY' | 'UNIT_PRICE_USAGE';
export type MeterType = 'ELECTRICITY' | 'WATER' | 'COLD_WATER' | 'HOT_WATER' | 'GAS' | null;

export interface ServicePricingConfig {
    id: string;
    service_code: string;
    service_name: string;
    meter_type: MeterType;
    billing_type: BillingType;
    unit_name: string;
    fixed_unit_price: number;
    vat_percentage: number;
    environmental_protection_fee_pct: number;
    effective_from_date: string;
    effective_to_date?: string | null;
    is_active: boolean;
    created_at?: string;
    updated_at?: string;
    tiers?: PricingTier[];
}

export interface TierBreakdownItem {
    tier_order: number;
    tier_name: string;
    min_usage: number;
    max_usage: number | null;
    unit_price: number;
    tier_usage: number;
    tier_amount: number;
}

export interface SimulationResult {
    usage: number;
    subtotal: number;
    vat_percentage: number;
    vat_amount: number;
    environmental_protection_fee_pct: number;
    environmental_fee: number;
    total_amount: number;
    tier_breakdowns: TierBreakdownItem[];
}

export interface CreatePricingConfigRequest {
    service_code: string;
    service_name: string;
    meter_type?: MeterType;
    billing_type: BillingType;
    unit_name: string;
    fixed_unit_price?: number;
    vat_percentage?: number;
    environmental_protection_fee_pct?: number;
    effective_from_date?: string;
    effective_to_date?: string | null;
    is_active?: boolean;
    tiers?: Omit<PricingTier, 'id' | 'pricing_config_id'>[];
}

class ServicePricingApiService {
    private baseUrl = '/api/v1/pricing-configs';

    private getAuthHeaders(): HeadersInit {
        let token = localStorage.getItem('smart_cassavas_token') || localStorage.getItem('access_token') || sessionStorage.getItem('access_token');
        if (!token) {
            const sessionStr = localStorage.getItem('smartcassavas_session') || localStorage.getItem('admin_auth_user') || localStorage.getItem('smart_user_session');
            if (sessionStr) {
                try {
                    const session = JSON.parse(sessionStr);
                    token = session.token || session.access_token;
                } catch {
                    // ignore
                }
            }
        }

        const authToken = token || '';

        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(authToken ? { Authorization: authToken.startsWith('Bearer ') ? authToken : `Bearer ${authToken}` } : {}),
        };
    }

    private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
        const url = `${this.baseUrl}${endpoint}`;
        const headers = {
            ...this.getAuthHeaders(),
            ...(options.headers || {}),
        };

        const response = await fetch(url, {
            ...options,
            headers,
        });

        const data = await response.json();

        if (!response.ok) {
            const error: any = new Error(data.message || 'Đã có lỗi xảy ra');
            error.response = { data, status: response.status };
            throw error;
        }

        return data;
    }

    async getPricingConfigs(params?: { search?: string; billing_type?: string; meter_type?: string; is_active?: boolean; page?: number; per_page?: number }): Promise<{ success: boolean; data: ServicePricingConfig[]; meta?: any; total: number }> {
        const query = new URLSearchParams();
        if (params?.search) query.append('search', params.search);
        if (params?.billing_type && params.billing_type !== 'ALL') query.append('billing_type', params.billing_type);
        if (params?.meter_type && params.meter_type !== 'ALL') query.append('meter_type', params.meter_type);
        if (params?.is_active !== undefined) query.append('is_active', String(params.is_active));
        if (params?.page) query.append('page', String(params.page));
        if (params?.per_page) query.append('per_page', String(params.per_page));

        const queryString = query.toString();
        const endpoint = queryString ? `?${queryString}` : '';

        return this.request<{ success: boolean; data: ServicePricingConfig[]; meta?: any; total: number }>(endpoint, {
            method: 'GET',
        });
    }

    async getPricingConfig(id: string): Promise<{ success: boolean; data: ServicePricingConfig }> {
        return this.request<{ success: boolean; data: ServicePricingConfig }>(`/${id}`, {
            method: 'GET',
        });
    }

    async createPricingConfig(data: CreatePricingConfigRequest): Promise<{ success: boolean; message: string; data: ServicePricingConfig }> {
        return this.request<{ success: boolean; message: string; data: ServicePricingConfig }>('', {
            method: 'POST',
            body: JSON.stringify(data),
        });
    }

    async updatePricingConfig(id: string, data: Partial<CreatePricingConfigRequest>): Promise<{ success: boolean; message: string; data: ServicePricingConfig }> {
        return this.request<{ success: boolean; message: string; data: ServicePricingConfig }>(`/${id}`, {
            method: 'PUT',
            body: JSON.stringify(data),
        });
    }

    async deletePricingConfig(id: string): Promise<{ success: boolean; message: string }> {
        return this.request<{ success: boolean; message: string }>(`/${id}`, {
            method: 'DELETE',
        });
    }

    async toggleActivePricingConfig(id: string): Promise<{ success: boolean; message: string; data: ServicePricingConfig }> {
        return this.request<{ success: boolean; message: string; data: ServicePricingConfig }>(`/${id}/toggle-active`, {
            method: 'PATCH',
        });
    }

    async getPricingTiers(configId: string): Promise<{ success: boolean; data: PricingTier[]; total: number }> {
        return this.request<{ success: boolean; data: PricingTier[]; total: number }>(`/${configId}/tiers`, {
            method: 'GET',
        });
    }

    async updatePricingTiers(configId: string, tiers: PricingTier[]): Promise<{ success: boolean; message: string; data: PricingTier[] }> {
        return this.request<{ success: boolean; message: string; data: PricingTier[] }>(`/${configId}/tiers`, {
            method: 'PUT',
            body: JSON.stringify({ tiers }),
        });
    }

    async simulateCalculation(configId: string, usage: number): Promise<{ success: boolean; data: SimulationResult }> {
        return this.request<{ success: boolean; data: SimulationResult }>(`/${configId}/simulate`, {
            method: 'POST',
            body: JSON.stringify({ usage }),
        });
    }
}

export const servicePricingApi = new ServicePricingApiService();
