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

    /**
     * Lấy hóa đơn tháng hiện tại (Chức năng 9)
     */
    async getCurrentMonthInvoice(params?: { apartment_id?: string; billing_period?: string }): Promise<{
        success: boolean;
        message: string;
        data: InvoiceModel;
    }> {
        const query = new URLSearchParams();
        if (params?.apartment_id) query.append('apartment_id', params.apartment_id);
        if (params?.billing_period) query.append('billing_period', params.billing_period);

        const url = `/api/v1/invoices/current-month${query.toString() ? '?' + query.toString() : ''}`;
        const res = await fetch(url, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải hóa đơn tháng hiện tại');
        return json;
    },

    /**
     * Lấy sao kê hóa đơn chi tiết phục vụ in ấn & hiển thị (Chức năng 9)
     */
    async getInvoiceStatement(invoiceId: string): Promise<{
        success: boolean;
        data: {
            invoice: InvoiceModel;
            summary: {
                subtotal_amount: number;
                tax_amount: number;
                previous_debt_amount: number;
                total_amount: number;
                paid_amount: number;
                remaining_balance: number;
                amount_in_words: string;
                is_paid: boolean;
            };
            categorized_items: {
                electricity?: any;
                water?: any;
                management?: any;
                parking: any[];
                others: any[];
            };
            payment_history: any[];
        };
    }> {
        const res = await fetch(`/api/v1/invoices/${invoiceId}/statement`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải sao kê chi tiết hóa đơn');
        return json;
    },

    /**
     * Lấy lịch sử giao dịch thanh toán các kỳ (Chức năng 10)
     */
    async getPaymentHistory(filters?: {
        apartment_id?: string;
        payment_gateway?: string;
        payment_status?: string;
        billing_period?: string;
        date_from?: string;
        date_to?: string;
        block_id?: string;
        search?: string;
        page?: number;
        per_page?: number;
    }): Promise<{
        success: boolean;
        data: {
            data: any[];
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    }> {
        const query = new URLSearchParams();
        if (filters) {
            Object.entries(filters).forEach(([key, val]) => {
                if (val !== undefined && val !== null && val !== '') {
                    query.append(key, String(val));
                }
            });
        }

        const res = await fetch(`/api/v1/payments/history?${query.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải lịch sử giao dịch');
        return json;
    },

    /**
     * Thống kê tổng hợp số tiền các giao dịch (Chức năng 10)
     */
    async getPaymentSummary(filters?: {
        apartment_id?: string;
        payment_gateway?: string;
        billing_period?: string;
        date_from?: string;
        date_to?: string;
    }): Promise<{
        success: boolean;
        data: {
            total_transactions: number;
            successful_transactions: number;
            total_amount: number;
            by_gateway: Record<string, { count: number; total: number }>;
        };
    }> {
        const query = new URLSearchParams();
        if (filters) {
            Object.entries(filters).forEach(([key, val]) => {
                if (val !== undefined && val !== null && val !== '') {
                    query.append(key, String(val));
                }
            });
        }

        const res = await fetch(`/api/v1/payments/summary?${query.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải thống kê giao dịch');
        return json;
    },

    /**
     * Lấy chi tiết biên lai thu tiền (Chức năng 10)
     */
    async getReceiptDetail(paymentId: string): Promise<{
        success: boolean;
        data: {
            payment: any;
            receipt: any;
            invoice: InvoiceModel;
        };
    }> {
        const res = await fetch(`/api/v1/payments/${paymentId}/receipt`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải chi tiết biên lai');
        return json;
    },

    /**
     * Gửi email nhắc nợ đơn lẻ qua Queue (Chức năng 11)
     */
    async sendDebtReminder(invoiceId: string): Promise<{
        success: boolean;
        message: string;
        data: any;
    }> {
        const res = await fetch(`/api/v1/invoices/${invoiceId}/send-reminder`, {
            method: 'POST',
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi gửi email nhắc nợ');
        return json;
    },

    /**
     * Gửi email nhắc nợ hàng loạt qua Queue (Chức năng 11)
     */
    async bulkSendDebtReminders(invoiceIds: string[]): Promise<{
        success: boolean;
        message: string;
        data: {
            queued_count: number;
            skipped_count: number;
            errors: string[];
        };
    }> {
        const res = await fetch('/api/v1/invoices/bulk-send-reminders', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify({ invoice_ids: invoiceIds }),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi gửi hàng loạt nhắc nợ');
        return json;
    },

    /**
     * Lấy lịch sử các đợt gửi email nhắc nợ (Chức năng 11)
     */
    async getDebtReminderLogs(filters?: {
        invoice_id?: string;
        apartment_id?: string;
        channel_status?: string;
        search?: string;
        page?: number;
        per_page?: number;
    }): Promise<{
        success: boolean;
        data: {
            data: any[];
            current_page: number;
            last_page: number;
            total: number;
        };
    }> {
        const query = new URLSearchParams();
        if (filters) {
            Object.entries(filters).forEach(([key, val]) => {
                if (val !== undefined && val !== null && val !== '') {
                    query.append(key, String(val));
                }
            });
        }

        const res = await fetch(`/api/v1/invoices/reminders/history?${query.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải lịch sử nhắc nợ');
        return json;
    },

    /**
     * Lấy toàn bộ dữ liệu Dashboard Thống kê Doanh thu (Chức năng 12)
     */
    async getRevenueAnalyticsDashboard(params?: { year?: number; period?: string }): Promise<{
        success: boolean;
        data: {
            year: number;
            period: string | null;
            summary: {
                total_billed: number;
                total_collected: number;
                total_debt: number;
                total_invoices: number;
                paid_invoices: number;
                overdue_invoices: number;
                collection_rate: number;
                previous_period: string;
                billed_growth_pct: number;
                collected_growth_pct: number;
            };
            monthly_trend: Array<{
                month: string;
                month_name: string;
                billed_amount: number;
                collected_amount: number;
                debt_amount: number;
                invoice_count: number;
                collection_rate: number;
            }>;
            revenue_by_category: Array<{
                item_type: string;
                label: string;
                total_amount: number;
                percentage: number;
                item_count: number;
                color: string;
            }>;
            payment_methods: Array<{
                gateway: string;
                label: string;
                total_amount: number;
                transaction_count: number;
                percentage: number;
                color: string;
            }>;
            top_debtors: Array<{
                apartment_id: string;
                apartment_number: string;
                block_name: string;
                resident_name: string;
                resident_phone: string;
                total_debt: number;
                unpaid_invoice_count: number;
                latest_due_date: string;
            }>;
        };
    }> {
        const query = new URLSearchParams();
        if (params?.year) query.append('year', String(params.year));
        if (params?.period) query.append('period', params.period);

        const res = await fetch(`/api/v1/invoices/analytics/dashboard?${query.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải dữ liệu phân tích doanh thu');
        return json;
    },

    /**
     * Lấy xu hướng doanh thu 12 tháng (Chức năng 12)
     */
    async getRevenueMonthlyTrend(year: number): Promise<{
        success: boolean;
        year: number;
        data: Array<{
            month: string;
            month_name: string;
            billed_amount: number;
            collected_amount: number;
            debt_amount: number;
            invoice_count: number;
            collection_rate: number;
        }>;
    }> {
        const res = await fetch(`/api/v1/invoices/analytics/monthly-trend?year=${year}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải xu hướng doanh thu');
        return json;
    },

    /**
     * Lấy cơ cấu doanh thu theo loại phí (Chức năng 12)
     */
    async getRevenueCategoryBreakdown(year: number, period?: string): Promise<{
        success: boolean;
        data: Array<{
            item_type: string;
            label: string;
            total_amount: number;
            percentage: number;
            item_count: number;
            color: string;
        }>;
    }> {
        const query = new URLSearchParams({ year: String(year) });
        if (period) query.append('period', period);

        const res = await fetch(`/api/v1/invoices/analytics/category-breakdown?${query.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải cơ cấu doanh thu');
        return json;
    },

    /**
     * Lấy top căn hộ còn nợ nhiều nhất (Chức năng 12)
     */
    async getTopDebtors(limit: number = 5): Promise<{
        success: boolean;
        data: Array<{
            apartment_id: string;
            apartment_number: string;
            block_name: string;
            resident_name: string;
            resident_phone: string;
            total_debt: number;
            unpaid_invoice_count: number;
            latest_due_date: string;
        }>;
    }> {
        const res = await fetch(`/api/v1/invoices/analytics/top-debtors?limit=${limit}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải danh sách căn hộ nợ');
        return json;
    },

    /**
     * Xem trước (Preview) Báo cáo Tài chính & Công nợ (Chức năng 13)
     */
    async getFinancialReportPreview(params?: {
        period?: string;
        block_id?: string;
        status?: string;
        report_type?: string;
    }): Promise<{
        success: boolean;
        data: {
            filters: any;
            summary: {
                total_invoices: number;
                total_billed: number;
                total_collected: number;
                total_debt: number;
                collection_rate: number;
            };
            rows: any[];
            generated_at: string;
        };
    }> {
        const query = new URLSearchParams();
        if (params?.period) query.append('period', params.period);
        if (params?.block_id) query.append('block_id', params.block_id);
        if (params?.status) query.append('status', params.status);
        if (params?.report_type) query.append('report_type', params.report_type);

        const res = await fetch(`/api/v1/reports/financial/preview?${query.toString()}`, {
            headers: getAuthHeaders(),
        });

        const json = await res.json();
        if (!res.ok) throw new Error(json.message || 'Lỗi tải bản xem trước báo cáo');
        return json;
    },

    /**
     * Tạo đường dẫn tải file Excel (.CSV UTF-8)
     */
    getFinancialReportExportExcelUrl(params?: {
        period?: string;
        block_id?: string;
        status?: string;
        report_type?: string;
    }): string {
        const query = new URLSearchParams();
        if (params?.period) query.append('period', params.period);
        if (params?.block_id) query.append('block_id', params.block_id);
        if (params?.status) query.append('status', params.status);
        if (params?.report_type) query.append('report_type', params.report_type);

        return `/api/v1/reports/financial/export-excel?${query.toString()}`;
    },

    /**
     * Tạo đường dẫn xem & in báo cáo PDF / HTML
     */
    getFinancialReportExportPdfUrl(params?: {
        period?: string;
        block_id?: string;
        status?: string;
        report_type?: string;
        auto_print?: boolean;
    }): string {
        const query = new URLSearchParams();
        if (params?.period) query.append('period', params.period);
        if (params?.block_id) query.append('block_id', params.block_id);
        if (params?.status) query.append('status', params.status);
        if (params?.report_type) query.append('report_type', params.report_type);
        if (params?.auto_print) query.append('auto_print', '1');

        return `/api/v1/reports/financial/export-pdf?${query.toString()}`;
    },
};
