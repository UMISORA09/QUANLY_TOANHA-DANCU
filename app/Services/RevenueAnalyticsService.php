<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueAnalyticsService
{
    /**
     * Lấy toàn bộ dữ liệu phân tích doanh thu cho Dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(?int $year = null, ?string $period = null): array
    {
        $year = $year ?: (int) Carbon::now()->format('Y');

        return [
            'year' => $year,
            'period' => $period,
            'summary' => $this->getSummaryMetrics($year, $period),
            'monthly_trend' => $this->getMonthlyTrend($year),
            'revenue_by_category' => $this->getRevenueByCategory($year, $period),
            'payment_methods' => $this->getRevenueByPaymentMethod($year),
            'top_debtors' => $this->getTopDebtors(5),
        ];
    }

    /**
     * Tổng hợp các chỉ số KPI doanh thu & công nợ chính.
     *
     * @return array<string, mixed>
     */
    public function getSummaryMetrics(int $year, ?string $period = null): array
    {
        $query = Invoice::query()->where('status', '!=', 'CANCELLED');

        if ($period) {
            $query->where('billing_period', $period);
        } else {
            $query->where('billing_period', 'like', "{$year}-%");
        }

        $totalBilled = (float) $query->sum('total_amount');
        $totalCollected = (float) $query->sum('paid_amount');
        $totalDebt = (float) $query->sum('remaining_balance');
        $totalInvoices = (int) $query->count();
        $paidInvoices = (int) (clone $query)->where('status', 'PAID')->count();
        $overdueInvoices = (int) (clone $query)->where('status', 'OVERDUE')->count();

        $collectionRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 2) : 0.0;

        // So sánh với kỳ trước (tháng trước)
        $previousPeriod = $this->getPreviousPeriod($period ?: Carbon::now()->format('Y-m'));
        $prevQuery = Invoice::query()->where('status', '!=', 'CANCELLED')->where('billing_period', $previousPeriod);
        $prevBilled = (float) $prevQuery->sum('total_amount');
        $prevCollected = (float) $prevQuery->sum('paid_amount');

        $billedGrowth = $prevBilled > 0 ? round((($totalBilled - $prevBilled) / $prevBilled) * 100, 2) : 0.0;
        $collectedGrowth = $prevCollected > 0 ? round((($totalCollected - $prevCollected) / $prevCollected) * 100, 2) : 0.0;

        return [
            'total_billed' => $totalBilled,
            'total_collected' => $totalCollected,
            'total_debt' => $totalDebt,
            'total_invoices' => $totalInvoices,
            'paid_invoices' => $paidInvoices,
            'overdue_invoices' => $overdueInvoices,
            'collection_rate' => $collectionRate,
            'previous_period' => $previousPeriod,
            'billed_growth_pct' => $billedGrowth,
            'collected_growth_pct' => $collectedGrowth,
        ];
    }

    /**
     * Xu hướng doanh thu & công nợ theo 12 tháng của năm.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMonthlyTrend(int $year): array
    {
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $monthStr = sprintf('%04d-%02d', $year, $m);

            $stats = Invoice::query()
                ->where('status', '!=', 'CANCELLED')
                ->where('billing_period', $monthStr)
                ->selectRaw('
                    COALESCE(SUM(total_amount), 0) as billed_amount,
                    COALESCE(SUM(paid_amount), 0) as collected_amount,
                    COALESCE(SUM(remaining_balance), 0) as debt_amount,
                    COUNT(id) as invoice_count
                ')
                ->first();

            $billed = (float) ($stats->billed_amount ?? 0);
            $collected = (float) ($stats->collected_amount ?? 0);
            $debt = (float) ($stats->debt_amount ?? 0);
            $count = (int) ($stats->invoice_count ?? 0);
            $rate = $billed > 0 ? round(($collected / $billed) * 100, 2) : 0.0;

            $months[] = [
                'month' => $monthStr,
                'month_name' => "T{$m}",
                'billed_amount' => $billed,
                'collected_amount' => $collected,
                'debt_amount' => $debt,
                'invoice_count' => $count,
                'collection_rate' => $rate,
            ];
        }

        return $months;
    }

    /**
     * Phân bổ cơ cấu doanh thu theo loại dịch vụ (Điện, Nước, Quản lý, Gửi xe, Khác).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRevenueByCategory(int $year, ?string $period = null): array
    {
        $query = InvoiceItem::query()
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', '!=', 'CANCELLED');

        if ($period) {
            $query->where('invoices.billing_period', $period);
        } else {
            $query->where('invoices.billing_period', 'like', "{$year}-%");
        }

        $items = $query->select(
            'invoice_items.service_code as item_type',
            DB::raw('COALESCE(SUM(invoice_items.total_line_amount), 0) as total_amount'),
            DB::raw('COUNT(invoice_items.id) as item_count')
        )
            ->groupBy('invoice_items.service_code')
            ->get();

        $grandTotal = (float) $items->sum('total_amount');

        $categoryLabels = [
            'ELECTRICITY' => 'Tiền Điện',
            'WATER' => 'Tiền Nước',
            'MANAGEMENT_FEE' => 'Phí Quản Lý Vận Hành',
            'PARKING_FEE' => 'Phí Gửi Xe',
            'OTHER' => 'Dịch Vụ Khác',
        ];

        $categoryColors = [
            'ELECTRICITY' => '#f59e0b', // Amber
            'WATER' => '#06b6d4',       // Cyan
            'MANAGEMENT_FEE' => '#6366f1', // Indigo
            'PARKING_FEE' => '#10b981', // Emerald
            'OTHER' => '#ec4899',       // Pink
        ];

        $result = [];
        foreach ($items as $item) {
            $amt = (float) $item->total_amount;
            $type = (string) $item->item_type;
            $pct = $grandTotal > 0 ? round(($amt / $grandTotal) * 100, 2) : 0.0;

            $result[] = [
                'item_type' => $type,
                'label' => $categoryLabels[$type] ?? $type,
                'total_amount' => $amt,
                'percentage' => $pct,
                'item_count' => (int) $item->item_count,
                'color' => $categoryColors[$type] ?? '#94a3b8',
            ];
        }

        // Sắp xếp theo doanh thu giảm dần
        usort($result, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        return $result;
    }

    /**
     * Cơ cấu thực thu theo phương thức thanh toán.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRevenueByPaymentMethod(int $year): array
    {
        $payments = Payment::query()
            ->where('payment_status', 'SUCCESS')
            ->whereYear('payment_time', $year)
            ->select(
                'payment_gateway',
                DB::raw('COALESCE(SUM(amount_paid), 0) as total_amount'),
                DB::raw('COUNT(id) as transaction_count')
            )
            ->groupBy('payment_gateway')
            ->get();

        $grandTotal = (float) $payments->sum('total_amount');

        $gatewayLabels = [
            'CASH' => 'Tiền Mặt',
            'BANK_TRANSFER' => 'Chuyển Khoản Ngân Hàng',
            'VIETQR' => 'Quét Mã VietQR',
            'VNPAY' => 'Cổng VNPAY',
            'MOMO' => 'Ví MoMo',
        ];

        $gatewayColors = [
            'CASH' => '#10b981',
            'BANK_TRANSFER' => '#3b82f6',
            'VIETQR' => '#8b5cf6',
            'VNPAY' => '#ef4444',
            'MOMO' => '#d946ef',
        ];

        $result = [];
        foreach ($payments as $payment) {
            $amt = (float) $payment->total_amount;
            $gw = (string) $payment->payment_gateway;
            $pct = $grandTotal > 0 ? round(($amt / $grandTotal) * 100, 2) : 0.0;

            $result[] = [
                'gateway' => $gw,
                'label' => $gatewayLabels[$gw] ?? $gw,
                'total_amount' => $amt,
                'transaction_count' => (int) $payment->transaction_count,
                'percentage' => $pct,
                'color' => $gatewayColors[$gw] ?? '#64748b',
            ];
        }

        return $result;
    }

    /**
     * Top căn hộ còn nợ đọng nhiều nhất.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopDebtors(int $limit = 5): array
    {
        $debtors = Invoice::query()
            ->where('status', '!=', 'CANCELLED')
            ->where('status', '!=', 'PAID')
            ->where('remaining_balance', '>', 0)
            ->with(['apartment.block', 'residentUser'])
            ->select(
                'apartment_id',
                'resident_user_id',
                DB::raw('SUM(remaining_balance) as total_debt'),
                DB::raw('COUNT(id) as unpaid_invoice_count'),
                DB::raw('MAX(due_date) as latest_due_date')
            )
            ->groupBy('apartment_id', 'resident_user_id')
            ->orderByDesc('total_debt')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($debtors as $row) {
            $apt = $row->apartment;
            $user = $row->residentUser;

            $result[] = [
                'apartment_id' => $row->apartment_id,
                'apartment_number' => $apt ? $apt->apartment_number : 'N/A',
                'block_name' => $apt && $apt->block ? $apt->block->block_name : 'N/A',
                'resident_name' => $user ? $user->full_name : 'Căn Hộ Đại Diện',
                'resident_phone' => $user ? $user->phone_number : '',
                'total_debt' => (float) $row->total_debt,
                'unpaid_invoice_count' => (int) $row->unpaid_invoice_count,
                'latest_due_date' => $row->latest_due_date,
            ];
        }

        return $result;
    }

    /**
     * Tính toán kỳ trước của YYYY-MM.
     */
    private function getPreviousPeriod(string $period): string
    {
        try {
            return Carbon::createFromFormat('Y-m', $period)->subMonth()->format('Y-m');
        } catch (\Throwable) {
            return Carbon::now()->subMonth()->format('Y-m');
        }
    }
}
