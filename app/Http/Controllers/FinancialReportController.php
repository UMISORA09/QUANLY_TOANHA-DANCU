<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialReportController extends Controller
{
    public function __construct(
        protected FinancialReportService $reportService
    ) {}

    /**
     * Lấy dữ liệu xem trước (Preview) Báo cáo Tài chính & Công nợ (JSON).
     */
    public function preview(Request $request): JsonResponse
    {
        $filters = [
            'period' => $request->input('period'),
            'block_id' => $request->input('block_id'),
            'status' => $request->input('status', 'ALL'),
            'report_type' => $request->input('report_type', 'debt_summary'),
        ];

        $data = $this->reportService->getReportData($filters);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Xuất Báo cáo Tài chính & Công nợ ra file Excel (.CSV UTF-8 BOM).
     */
    public function exportExcel(Request $request): Response|StreamedResponse
    {
        $filters = [
            'period' => $request->input('period'),
            'block_id' => $request->input('block_id'),
            'status' => $request->input('status', 'ALL'),
            'report_type' => $request->input('report_type', 'debt_summary'),
        ];

        $csvContent = $this->reportService->generateCsvContent($filters);

        $periodLabel = $filters['period'] ? str_replace('-', '_', $filters['period']) : date('Y_m');
        $fileName = "Bao_Cao_Tai_Chinh_Cong_No_Ky_{$periodLabel}.csv";

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Xuất Báo cáo Tài chính mẫu in ấn / PDF (A4 Landscape).
     */
    public function exportPdf(Request $request): Response
    {
        $filters = [
            'period' => $request->input('period'),
            'block_id' => $request->input('block_id'),
            'status' => $request->input('status', 'ALL'),
            'report_type' => $request->input('report_type', 'debt_summary'),
        ];

        $report = $this->reportService->getReportData($filters);

        return response()->view('reports.financial_statement', [
            'report' => $report,
        ]);
    }
}
