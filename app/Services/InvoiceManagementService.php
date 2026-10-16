<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MeterReading;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InvoiceManagementService
{
    /**
     * Danh sách hóa đơn phân trang với các bộ lọc trạng thái và tìm kiếm chuyên sâu
     *
     * @param  array<string, mixed>  $filters
     */
    public function listInvoices(array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::with([
            'apartment.block',
            'apartment.floor',
            'residentUser',
            'items',
            'batch',
        ]);

        $this->applyFilters($query, $filters);

        // Sắp xếp
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['created_at', 'issue_date', 'due_date', 'total_amount', 'remaining_balance', 'status'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage);
    }

    /**
     * Lấy dữ liệu KPI tổng hợp tài chính của danh sách hóa đơn theo bộ lọc
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getSummary(array $filters = []): array
    {
        $query = Invoice::query();
        $this->applyFilters($query, $filters);

        $now = Carbon::now()->format('Y-m-d');

        $totalInvoices = (clone $query)->count();
        $totalAmount = (float) (clone $query)->sum('total_amount');
        $paidAmount = (float) (clone $query)->sum('paid_amount');
        $remainingBalance = (float) (clone $query)->sum('remaining_balance');

        // Phân loại số lượng theo trạng thái
        $counts = [
            'DRAFT' => (clone $query)->where('status', 'DRAFT')->count(),
            'ISSUED' => (clone $query)->where('status', 'ISSUED')->where('due_date', '>=', $now)->count(),
            'PARTIAL' => (clone $query)->where('status', 'PARTIAL')->where('due_date', '>=', $now)->count(),
            'PAID' => (clone $query)->where('status', 'PAID')->count(),
            'OVERDUE' => (clone $query)->where(function ($q) use ($now) {
                $q->where('status', 'OVERDUE')
                    ->orWhere(function ($sub) use ($now) {
                        $sub->whereIn('status', ['ISSUED', 'PARTIAL'])
                            ->where('due_date', '<', $now);
                    });
            })->count(),
            'CANCELLED' => (clone $query)->where('status', 'CANCELLED')->count(),
        ];

        $collectionRate = $totalAmount > 0 ? round(($paidAmount / $totalAmount) * 100, 1) : 0.0;

        return [
            'total_invoices' => $totalInvoices,
            'total_amount' => round($totalAmount, 2),
            'paid_amount' => round($paidAmount, 2),
            'remaining_balance' => round($remainingBalance, 2),
            'collection_rate' => $collectionRate,
            'counts_by_status' => $counts,
        ];
    }

    /**
     * Xem thông tin chi tiết một hóa đơn và danh sách các mục dịch vụ
     */
    public function getInvoiceDetail(string $invoiceId): Invoice
    {
        return Invoice::with([
            'apartment.block',
            'apartment.floor',
            'residentUser',
            'items.meterReading.meter',
            'payments.receipt',
            'batch',
        ])->findOrFail($invoiceId);
    }

    /**
     * Lấy hóa đơn của kỳ phí hiện tại (hoặc kỳ phí được chỉ định)
     */
    public function getCurrentMonthInvoice(?string $userId = null, ?string $apartmentId = null, ?string $period = null): ?Invoice
    {
        $targetPeriod = $period ?: Carbon::now()->format('Y-m');

        $query = Invoice::with([
            'apartment.block',
            'apartment.floor',
            'residentUser',
            'items.meterReading.meter',
            'payments.receipt',
            'batch',
        ]);

        if ($apartmentId) {
            $query->where('apartment_id', $apartmentId);
        } elseif ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->where('resident_user_id', $userId)
                    ->orWhereHas('apartment.residents', function ($rq) use ($userId) {
                        $rq->where('user_id', $userId)->where('is_active', 1);
                    });
            });
        }

        // Ưu tiên kỳ phí hiện tại
        $invoice = (clone $query)->where('billing_period', $targetPeriod)->first();

        // Nếu không có hóa đơn tháng hiện tại, lấy hóa đơn mới nhất gần nhất
        if (! $invoice) {
            $invoice = $query->orderBy('billing_period', 'desc')->first();
        }

        return $invoice;
    }

    /**
     * Xuất sao kê hóa đơn chi tiết (Detailed Statement) phục vụ hiển thị & in ấn
     *
     * @return array<string, mixed>
     */
    public function getDetailedStatement(string $invoiceId): array
    {
        $invoice = $this->getInvoiceDetail($invoiceId);

        $electricityItem = $invoice->items->first(fn ($item) => str_contains(strtoupper($item->service_code), 'ELECTRIC'));
        $waterItem = $invoice->items->first(fn ($item) => str_contains(strtoupper($item->service_code), 'WATER'));
        $managementItem = $invoice->items->first(fn ($item) => str_contains(strtoupper($item->service_code), 'MANAGEMENT'));
        $parkingItems = $invoice->items->filter(fn ($item) => str_contains(strtoupper($item->service_code), 'PARKING'));
        $otherItems = $invoice->items->filter(fn ($item) => ! in_array($item->id, array_filter([
            $electricityItem?->id,
            $waterItem?->id,
            $managementItem?->id,
            ...$parkingItems->pluck('id')->all(),
        ])));

        $amountInWords = $this->convertNumberToWords((int) round($invoice->total_amount)).' đồng chẵn';

        return [
            'invoice' => $invoice,
            'summary' => [
                'subtotal_amount' => (float) $invoice->subtotal_amount,
                'tax_amount' => (float) $invoice->tax_amount,
                'previous_debt_amount' => (float) $invoice->previous_debt_amount,
                'total_amount' => (float) $invoice->total_amount,
                'paid_amount' => (float) $invoice->paid_amount,
                'remaining_balance' => (float) $invoice->remaining_balance,
                'amount_in_words' => $amountInWords,
                'is_paid' => $invoice->status === 'PAID',
            ],
            'categorized_items' => [
                'electricity' => $electricityItem,
                'water' => $waterItem,
                'management' => $managementItem,
                'parking' => $parkingItems->values(),
                'others' => $otherItems->values(),
            ],
            'payment_history' => $invoice->payments,
        ];
    }

    /**
     * Chuyển đổi số tiền thành chữ tiếng Việt
     */
    public function convertNumberToWords(int $number): string
    {
        if ($number === 0) {
            return 'Không';
        }

        $units = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];
        $digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

        $result = '';
        $unitIdx = 0;

        while ($number > 0) {
            $group = $number % 1000;
            if ($group > 0) {
                $groupStr = '';
                $hundreds = intdiv($group, 100);
                $tens = intdiv($group % 100, 10);
                $ones = $group % 10;

                if ($hundreds > 0 || $number >= 1000) {
                    $groupStr .= $digits[$hundreds].' trăm ';
                }

                if ($tens > 1) {
                    $groupStr .= $digits[$tens].' mươi ';
                    if ($ones === 1) {
                        $groupStr .= 'mốt ';
                    } elseif ($ones === 5) {
                        $groupStr .= 'lăm ';
                    } elseif ($ones > 0) {
                        $groupStr .= $digits[$ones].' ';
                    }
                } elseif ($tens === 1) {
                    $groupStr .= 'mười ';
                    if ($ones === 5) {
                        $groupStr .= 'lăm ';
                    } elseif ($ones > 0) {
                        $groupStr .= $digits[$ones].' ';
                    }
                } elseif ($tens === 0 && $ones > 0) {
                    if ($hundreds > 0 || $number >= 1000) {
                        $groupStr .= 'lẻ '.$digits[$ones].' ';
                    } else {
                        $groupStr .= $digits[$ones].' ';
                    }
                }

                $result = trim($groupStr).$units[$unitIdx].' '.$result;
            }

            $number = intdiv($number, 1000);
            $unitIdx++;
        }

        $result = trim($result);

        return mb_strtoupper(mb_substr($result, 0, 1)).mb_substr($result, 1);
    }

    /**
     * Hủy hóa đơn và mở khóa chỉ số điện/nước liên quan
     *
     * @throws \Exception
     */
    public function cancelInvoice(string $invoiceId, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $reason) {
            $invoice = Invoice::with('items')->findOrFail($invoiceId);

            if ($invoice->paid_amount > 0) {
                throw new \DomainException("Không thể hủy hóa đơn {$invoice->invoice_number} vì đã phát sinh số tiền thanh toán ({$invoice->paid_amount} đ).");
            }

            if ($invoice->status === 'CANCELLED') {
                throw new \DomainException("Hóa đơn {$invoice->invoice_number} đã ở trạng thái hủy trước đó.");
            }

            // Mở khóa các chỉ số điện/nước liên quan
            $meterReadingIds = $invoice->items
                ->pluck('meter_reading_id')
                ->filter()
                ->all();

            if (! empty($meterReadingIds)) {
                MeterReading::whereIn('id', $meterReadingIds)
                    ->update(['is_locked_for_billing' => false]);
            }

            $currentNotes = $invoice->notes ?? '';
            $appendReason = $reason ? " [Đã hủy: {$reason} vào ".now()->format('d/m/Y H:i').']' : ' [Đã hủy vào '.now()->format('d/m/Y H:i').']';

            $invoice->update([
                'status' => 'CANCELLED',
                'notes' => $currentNotes.$appendReason,
                'remaining_balance' => 0.00,
            ]);

            return $invoice->fresh(['apartment.block', 'residentUser', 'items']);
        });
    }

    /**
     * Hủy hàng loạt hóa đơn chưa thanh toán
     *
     * @param  array<string>  $invoiceIds
     * @return array{cancelled_count: int, failed_count: int, errors: array<string>}
     */
    public function bulkCancelInvoices(array $invoiceIds, ?string $reason = null): array
    {
        $cancelledCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($invoiceIds as $id) {
            try {
                $this->cancelInvoice($id, $reason);
                $cancelledCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = $e->getMessage();
            }
        }

        return [
            'cancelled_count' => $cancelledCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Áp dụng các điều kiện lọc vào Query
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $now = Carbon::now()->format('Y-m-d');

        // 1. Lọc theo trạng thái
        if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
            $status = strtoupper($filters['status']);

            if ($status === 'OVERDUE') {
                $query->where(function ($q) use ($now) {
                    $q->where('status', 'OVERDUE')
                        ->orWhere(function ($sub) use ($now) {
                            $sub->whereIn('status', ['ISSUED', 'PARTIAL'])
                                ->where('due_date', '<', $now);
                        });
                });
            } elseif ($status === 'ISSUED') {
                $query->where('status', 'ISSUED')
                    ->where('due_date', '>=', $now);
            } elseif ($status === 'PARTIAL') {
                $query->where('status', 'PARTIAL')
                    ->where('due_date', '>=', $now);
            } else {
                $query->where('status', $status);
            }
        }

        // 2. Lọc theo kỳ hóa đơn
        if (! empty($filters['billing_period'])) {
            $query->where('billing_period', $filters['billing_period']);
        }

        // 3. Lọc theo khối tòa nhà
        if (! empty($filters['block_id'])) {
            $query->whereHas('apartment', function ($q) use ($filters) {
                $q->where('block_id', $filters['block_id']);
            });
        }

        // 4. Lọc theo khoảng ngày phát hành
        if (! empty($filters['issue_date_from'])) {
            $query->where('issue_date', '>=', $filters['issue_date_from']);
        }
        if (! empty($filters['issue_date_to'])) {
            $query->where('issue_date', '<=', $filters['issue_date_to']);
        }

        // 5. Tìm kiếm từ khóa (Số hóa đơn, số căn, tên cư dân, điện thoại)
        if (! empty($filters['search'])) {
            $keyword = trim($filters['search']);
            $query->where(function ($q) use ($keyword) {
                $q->where('invoice_number', 'like', "%{$keyword}%")
                    ->orWhereHas('apartment', function ($aptQ) use ($keyword) {
                        $aptQ->where('apartment_number', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('residentUser', function ($uQ) use ($keyword) {
                        $uQ->where('full_name', 'like', "%{$keyword}%")
                            ->orWhere('phone_number', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%");
                    });
            });
        }
    }
}
