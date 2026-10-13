<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FinancialReportService
{
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
                'apartment_number' => $inv->apartment ? $inv->apartment->apartment_number : 'N/A',
                'block_name' => $inv->apartment && $inv->apartment->block ? $inv->apartment->block->block_name : 'N/A',
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
                'total_amount' => (float) $inv->total_amount,
                'paid_amount' => (float) $inv->paid_amount,
                'remaining_balance' => (float) $inv->remaining_balance,
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
            'CANCELLED' => 'Đã Hủy',
            default => $status,
        };
    }
}
