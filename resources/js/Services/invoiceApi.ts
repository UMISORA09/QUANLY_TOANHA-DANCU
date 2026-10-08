/**
 * API Service for Invoice Management (Quản lý và Lọc trạng thái Hóa đơn)
 */

export interface InvoiceItemModel {
    id: string;
    invoice_id: string;
    service_code: string;
    item_description: string;
    meter_reading_id?: string;
    previous_reading?: number;
    current_reading?: number;
    quantity: number;
    unit_name: string;
    unit_price: number;
    amount_before_tax: number;
    vat_percentage: number;
    vat_amount: number;
    environmental_fee_amount: number;
    total_line_amount: number;
    tier_calculation_details?: Array<{
        tier_order: number;
        tier_name: string;
        min_usage: number;
        max_usage?: number;
        unit_price: number;
        tier_usage: number;
        tier_amount: number;
    }>;
}

export interface InvoiceModel {
    id: string;
    invoice_number: string;
    batch_id?: string;
    apartment_id: string;
    resident_user_id: string;
    billing_period: string;
    issue_date: string;
    due_date: string;
    subtotal_amount: number;
    tax_amount: number;
    discount_amount: number;
    previous_debt_amount: number;
    total_amount: number;
    paid_amount: number;
    remaining_balance: number;
    status: 'DRAFT' | 'ISSUED' | 'PARTIAL' | 'PAID' | 'OVERDUE' | 'CANCELLED';
    notes?: string;
    e_invoice_provider?: string;
    e_invoice_number?: string;
    created_at: string;
    apartment?: {
        id: string;
        apartment_number: string;
        gross_floor_area_sqm?: number;
        net_usable_area_sqm?: number;
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
    resident_user?: {
        id: string;
        full_name: string;
        phone_number?: string;
        email?: string;
    };
    items?: InvoiceItemModel[];
}

export interface InvoiceSummaryModel {
    total_invoices: number;
    total_amount: number;
    paid_amount: number;
    remaining_balance: number;
    collection_rate: number;
    counts_by_status: {
        DRAFT: number;
        ISSUED: number;
        PARTIAL: number;
        PAID: number;
        OVERDUE: number;
        CANCELLED: number;
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

export const invoiceApi = {
    /**
     * Danh sách hóa đơn phân trang & lọc
     */
    async getInvoices(filters?: {
        status?: string;
        billing_period?: string;
        block_id?: string;
        issue_date_from?: string;
        issue_date_to?: string;
        search?: string;
        sort_by?: string;
        sort_order?: string;
        page?: number;
        per_page?: number;
    }): Promise<{
        success: boolean;
        data: {
            data: InvoiceModel[];
            current_page: number;
            last_page: number;
            total: number;
        };
    }> {
        const params = new URLSearchParams();
        if (filters?.status && filters.status !== 'ALL') params.append('status', filters.status);
        if (filters?.billing_period) params.append('billing_period', filters.billing_period);
        if (filters?.block_id) params.append('block_id', filters.block_id);
        if (filters?.issue_date_from) params.append('issue_date_from', filters.issue_date_from);
        if (filters?.issue_date_to) params.append('issue_date_to', filters.issue_date_to);
        if (filters?.search) params.append('search', filters.search);
        if (filters?.sort_by) params.append('sort_by', filters.sort_by);
        if (filters?.sort_order) params.append('sort_order', filters.sort_order);
        if (filters?.page) params.append('page', filters.page.toString());
        if (filters?.per_page) params.append('per_page', filters.per_page.toString());

        const res = await fetch(`/api/v1/invoices?${params.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải danh sách hóa đơn');
        return json;
    },

    /**
     * Thống kê tài chính KPI
     */
    async getSummary(filters?: {
        status?: string;
        billing_period?: string;
        block_id?: string;
        issue_date_from?: string;
        issue_date_to?: string;
        search?: string;
    }): Promise<{ success: boolean; data: InvoiceSummaryModel }> {
        const params = new URLSearchParams();
        if (filters?.status && filters.status !== 'ALL') params.append('status', filters.status);
        if (filters?.billing_period) params.append('billing_period', filters.billing_period);
        if (filters?.block_id) params.append('block_id', filters.block_id);
        if (filters?.issue_date_from) params.append('issue_date_from', filters.issue_date_from);
        if (filters?.issue_date_to) params.append('issue_date_to', filters.issue_date_to);
        if (filters?.search) params.append('search', filters.search);

        const res = await fetch(`/api/v1/invoices/summary?${params.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải thống kê hóa đơn');
        return json;
    },

    /**
     * Chi tiết một hóa đơn
     */
    async getInvoiceDetail(id: string): Promise<{ success: boolean; data: InvoiceModel }> {
        const res = await fetch(`/api/v1/invoices/${id}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải chi tiết hóa đơn');
        return json;
    },

    /**
     * Hủy một hóa đơn
     */
    async cancelInvoice(id: string, reason?: string): Promise<{ success: boolean; message: string; data: InvoiceModel }> {
        const res = await fetch(`/api/v1/invoices/${id}/cancel`, {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({ reason }),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi hủy hóa đơn');
        return json;
    },

    /**
     * Hủy hàng loạt hóa đơn
     */
    async bulkCancelInvoices(invoiceIds: string[], reason?: string): Promise<{
        success: boolean;
        message: string;
        data: {
            cancelled_count: number;
            failed_count: number;
            errors: string[];
        };
    }> {
        const res = await fetch('/api/v1/invoices/bulk-cancel', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({ invoice_ids: invoiceIds, reason }),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi hủy hàng loạt hóa đơn');
        return json;
    },

    /**
     * Thu tiền và gạch nợ tức thời (Chức năng 8)
     */
    async collectPayment(payload: {
        invoice_id: string;
        amount: number;
        payment_method: string;
        transaction_id?: string;
        notes?: string;
        idempotency_key?: string;
    }): Promise<{
        success: boolean;
        message: string;
        data: {
            payment: any;
            receipt: any;
            invoice: InvoiceModel;
            is_fully_paid: boolean;
            is_duplicate?: boolean;
        };
    }> {
        const res = await fetch('/api/v1/payments/collect', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(payload),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi xử lý thu tiền');
        return json;
    },

    /**
     * Lấy dữ liệu và mã VietQR cho hóa đơn
     */
    async getVietQrPayload(invoiceId: string): Promise<{
        success: boolean;
        data: {
            invoice_id: string;
            invoice_number: string;
            apartment_number: string;
            amount_due: number;
            transfer_content: string;
            bank_bin: string;
            bank_account_number: string;
            bank_account_name: string;
            qr_image_url: string;
        };
    }> {
        const res = await fetch(`/api/v1/invoices/${invoiceId}/vietqr-payload`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tạo mã VietQR');
        return json;
    },
};
