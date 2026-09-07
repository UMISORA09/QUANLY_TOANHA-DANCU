<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\UnitPrice;
use Illuminate\Database\Eloquent\Collection;

/**
 * BillingQueryService — Public API cho module khác truy vấn data hóa đơn.
 *
 * Các module khác KHÔNG được query trực tiếp Model của Billing.
 * Thay vào đó, inject service này qua Dependency Injection.
 */
class BillingQueryService
{
    /**
     * Lấy danh sách hóa đơn theo cư dân.
     */
    public function getInvoicesByResident(int $residentId): Collection
    {
        return Invoice::where('resident_id', $residentId)
            ->with('items')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Lấy hóa đơn theo căn hộ và tháng.
     */
    public function getInvoiceByApartmentAndMonth(int $apartmentId, int $month, int $year): ?Invoice
    {
        return Invoice::where('apartment_id', $apartmentId)
            ->where('month', $month)
            ->where('year', $year)
            ->with('items')
            ->first();
    }

    /**
     * Lấy tổng nợ chưa thanh toán của cư dân.
     */
    public function getOutstandingBalance(int $residentId): float
    {
        return (float) Invoice::where('resident_id', $residentId)
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_OVERDUE])
            ->sum('total_amount');
    }

    /**
     * Lấy đơn giá hiện hành theo loại.
     */
    public function getCurrentUnitPrice(string $type): ?UnitPrice
    {
        return UnitPrice::currentPrice($type)->first();
    }
}
