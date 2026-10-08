/**
 * API Service for Invoice Batch Generation (Sinh Hóa Đơn Hàng Loạt Tự Động)
 */

export interface InvoicePreviewItem {
    apartment_id: string;
    apartment_number: string;
    block_code: string;
    block_name: string;
    floor_number: number | string;
    area_sqm: number;
    resident_name: string;
    has_existing_invoice: boolean;
    existing_invoice_number?: string;
    existing_invoice_status?: string;
    has_electricity_reading: boolean;
    has_water_reading: boolean;
    items_count: number;
    subtotal_amount: number;
    tax_amount: number;
    previous_debt_amount: number;
    total_amount: number;
    items_preview: Array<{
        service_code: string;
        item_description: string;
        quantity: number;
        unit_name: string;
        unit_price: number;
        amount_before_tax: number;
        vat_percentage: number;
        vat_amount: number;
        environmental_fee_amount: number;
        total_line_amount: number;
        tier_calculation_details?: any[];
    }>;
}

export interface InvoicePreviewResponse {
    billing_period: string;
    block_id?: string | null;
    total_apartments: number;
    existing_invoices_count: number;
    eligible_for_generation: number;
    missing_electricity_readings: number;
    missing_water_readings: number;
    total_estimated_subtotal: number;
    total_estimated_tax: number;
    total_estimated_previous_debt: number;
    total_estimated_amount: number;
    preview_items: InvoicePreviewItem[];
}

export interface InvoiceBatchModel {
    id: string;
    batch_number: string;
    billing_period: string;
    block_id?: string | null;
    executed_by_user_id: string;
    total_apartments_processed: number;
    total_invoices_created: number;
    total_amount_calculated: number;
    status: 'RUNNING' | 'COMPLETED' | 'FAILED';
    error_logs: Array<{
        apartment_id: string;
        apartment_number: string;
        message: string;
    }>;
    started_at: string;
    completed_at?: string;
    block?: {
        id: string;
        block_code: string;
        block_name: string;
    };
    executed_by?: {
        id: string;
        full_name: string;
        email: string;
    };
}

const getAuthHeaders = () => {
    const token = localStorage.getItem('auth_token') || localStorage.getItem('token') || 'smart_token_admin_demo';
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: token.startsWith('Bearer ') ? token : `Bearer ${token}`,
    };
};

export const invoiceBatchApi = {
    /**
     * Chạy mô phỏng tính toán (Dry-Run Preview)
     */
    async previewBatch(params: {
        billing_period: string;
        block_id?: string;
        include_previous_debt?: boolean;
        overwrite_existing?: boolean;
    }): Promise<{ success: boolean; data: InvoicePreviewResponse }> {
        const res = await fetch('/api/v1/invoices/batch/preview', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(params),
        });

        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Lỗi khi mô phỏng dự toán sinh hóa đơn');
        }
        return json;
    },

    /**
     * Thực thi sinh hóa đơn tự động hàng loạt
     */
    async generateBatch(params: {
        billing_period: string;
        block_id?: string;
        issue_date?: string;
        due_date?: string;
        include_previous_debt?: boolean;
        overwrite_existing?: boolean;
        notes?: string;
    }): Promise<{
        success: boolean;
        message: string;
        data: {
            batch: InvoiceBatchModel;
            total_invoices_created: number;
            total_amount_calculated: number;
            skipped_count: number;
            errors_count: number;
        };
    }> {
        const res = await fetch('/api/v1/invoices/batch/generate', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(params),
        });

        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Lỗi khi thực thi sinh hóa đơn hàng loạt');
        }
        return json;
    },

    /**
     * Danh sách lịch sử các đợt phát hành hóa đơn
     */
    async getBatches(filters?: {
        billing_period?: string;
        block_id?: string;
        status?: string;
        page?: number;
    }): Promise<{
        success: boolean;
        data: {
            data: InvoiceBatchModel[];
            current_page: number;
            last_page: number;
            total: number;
        };
    }> {
        const params = new URLSearchParams();
        if (filters?.billing_period) params.append('billing_period', filters.billing_period);
        if (filters?.block_id) params.append('block_id', filters.block_id);
        if (filters?.status) params.append('status', filters.status);
        if (filters?.page) params.append('page', filters.page.toString());

        const res = await fetch(`/api/v1/invoices/batches?${params.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Lỗi tải lịch sử đợt sinh hóa đơn');
        }
        return json;
    },

    /**
     * Chi tiết một đợt sinh hóa đơn
     */
    async getBatchDetail(batchId: string): Promise<{
        success: boolean;
        data: {
            batch: InvoiceBatchModel;
            invoices: {
                data: any[];
                total: number;
            };
        };
    }> {
        const res = await fetch(`/api/v1/invoices/batches/${batchId}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Lỗi tải chi tiết đợt sinh hóa đơn');
        }
        return json;
    },
};
