<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Services\MeterReadingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class MeterReadingController extends Controller
{
    public function __construct(
        protected MeterReadingService $service
    ) {}

    /**
     * Render giao diện web quản lý chốt chỉ số điện nước
     */
    public function renderPage(Request $request): InertiaResponse
    {
        $currentCycle = $request->input('cycle', Carbon::now()->format('Y-m'));
        $blocks = Block::orderBy('block_name')->get(['id', 'block_code', 'block_name']);

        return Inertia::render('Admin/MeterReadingManagement', [
            'initialCycle' => $currentCycle,
            'blocks' => $blocks,
        ]);
    }

    /**
     * Thống kê KPI tiến độ chốt số kỳ
     */
    public function summary(Request $request): JsonResponse
    {
        $cycle = $request->input('cycle', Carbon::now()->format('Y-m'));
        $blockId = $request->input('block_id');

        try {
            $summary = $this->service->getSummary($cycle, $blockId);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi lấy thông tin tổng quan: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Danh sách đồng hồ và trạng thái chốt số trong kỳ
     */
    public function indexMeters(Request $request): JsonResponse
    {
        $filters = [
            'cycle' => $request->input('cycle', Carbon::now()->format('Y-m')),
            'search' => $request->input('search'),
            'meter_type' => $request->input('meter_type'),
            'block_id' => $request->input('block_id'),
            'floor_id' => $request->input('floor_id'),
            'apartment_id' => $request->input('apartment_id'),
            'recorded_status' => $request->input('recorded_status'),
        ];

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $this->service->listMeters($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Khai báo đồng hồ đo mới
     */
    public function storeMeter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apartment_id' => 'required|uuid|exists:apartments,id',
            'meter_type' => 'required|string|in:ELECTRICITY,WATER,COLD_WATER',
            'meter_code' => 'nullable|string|max:60',
            'installation_date' => 'nullable|date',
            'initial_reading' => 'nullable|numeric|min:0',
            'multiplier_factor' => 'nullable|numeric|min:0.01',
            'calibration_due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $meter = $this->service->createMeter($validated);

            return response()->json([
                'success' => true,
                'message' => 'Đã khai báo đồng hồ đo mới thành công.',
                'data' => $meter,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Lấy danh sách bản ghi chốt số
     */
    public function indexReadings(Request $request): JsonResponse
    {
        $filters = [
            'cycle' => $request->input('cycle', Carbon::now()->format('Y-m')),
            'meter_type' => $request->input('meter_type'),
            'block_id' => $request->input('block_id'),
            'apartment_id' => $request->input('apartment_id'),
            'search' => $request->input('search'),
            'is_abnormal' => $request->input('is_abnormal'),
        ];

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $this->service->listReadings($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Chốt chỉ số đo thủ công qua form
     */
    public function storeReading(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'meter_id' => 'required|uuid|exists:meters,id',
            'billing_cycle' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'current_reading' => 'required|numeric|min:0',
            'previous_reading' => 'nullable|numeric|min:0',
            'period_start_date' => 'nullable|date',
            'period_end_date' => 'nullable|date',
            'meter_photo_url' => 'nullable|string|max:500',
            'photo' => 'nullable|image|max:10240', // Cho phép upload file ảnh tới 10MB
            'force_reset' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        // Nếu có upload ảnh qua file multipart
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('meter-readings', 'public');
            $validated['meter_photo_url'] = Storage::url($path);
        }

        try {
            $reading = $this->service->recordManualReading($validated, $request->user()?->id);

            return response()->json([
                'success' => true,
                'message' => 'Ghi nhận chỉ số đo thành công.',
                'data' => $reading,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Chỉnh sửa bản ghi chỉ số đo
     */
    public function updateReading(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'current_reading' => 'required|numeric|min:0',
            'previous_reading' => 'nullable|numeric|min:0',
            'meter_photo_url' => 'nullable|string|max:500',
            'is_abnormal_consumption' => 'nullable|boolean',
            'abnormal_reason' => 'nullable|string|max:255',
            'force_reset' => 'nullable|boolean',
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('meter-readings', 'public');
            $validated['meter_photo_url'] = Storage::url($path);
        }

        try {
            $reading = $this->service->updateReading($id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật chỉ số thành công.',
                'data' => $reading,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Xóa bản ghi chỉ số đo
     */
    public function destroyReading(string $id): JsonResponse
    {
        try {
            $this->service->deleteReading($id);

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa bản ghi chỉ số thành công.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Khóa sổ kỳ chốt số
     */
    public function lockCycle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_cycle' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'block_id' => 'nullable|uuid|exists:blocks,id',
        ]);

        try {
            $updated = $this->service->lockCycle($validated['billing_cycle'], $validated['block_id'] ?? null);

            return response()->json([
                'success' => true,
                'message' => "Đã khóa sổ kỳ {$validated['billing_cycle']} thành công ({$updated} bản ghi).",
                'locked_count' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi khóa sổ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mở khóa sổ kỳ chốt số
     */
    public function unlockCycle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_cycle' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'block_id' => 'nullable|uuid|exists:blocks,id',
        ]);

        try {
            $updated = $this->service->unlockCycle($validated['billing_cycle'], $validated['block_id'] ?? null);

            return response()->json([
                'success' => true,
                'message' => "Đã mở khóa sổ kỳ {$validated['billing_cycle']} ({$updated} bản ghi).",
                'unlocked_count' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi mở khóa sổ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tải tệp mẫu Excel/CSV ghi chỉ số đo kỳ hiện tại
     */
    public function downloadTemplate(Request $request)
    {
        $cycle = $request->input('cycle', Carbon::now()->format('Y-m'));
        $blockId = $request->input('block_id');
        $meterType = $request->input('meter_type');

        try {
            $csvContent = $this->service->generateTemplate($cycle, $blockId, $meterType);

            return response($csvContent, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"mau_chot_chi_so_{$cycle}.csv\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tạo file mẫu: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import danh sách chỉ số điện nước hàng loạt từ file Excel/CSV
     */
    public function importExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // Cho phép đến 10MB
            'billing_cycle' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'block_id' => 'nullable|uuid|exists:blocks,id',
            'meter_type' => 'nullable|string|in:ELECTRICITY,WATER,COLD_WATER,ALL',
        ]);

        $uploadedFile = $request->file('file');
        $originalName = $uploadedFile->getClientOriginalName();
        $storedPath = $uploadedFile->store('meter-import-batches', 'local');
        $fullPath = Storage::disk('local')->path($storedPath);

        try {
            $batch = $this->service->importFromCsv(
                $fullPath,
                $originalName,
                $request->input('billing_cycle'),
                $request->input('block_id'),
                $request->input('meter_type'),
                $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => "Import hoàn tất: {$batch->success_records}/{$batch->total_records} bản ghi thành công.",
                'data' => $batch,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Danh sách lịch sử các đợt import hàng loạt
     */
    public function indexBatches(Request $request): JsonResponse
    {
        $filters = [
            'cycle' => $request->input('cycle'),
            'meter_type' => $request->input('meter_type'),
            'block_id' => $request->input('block_id'),
        ];

        $perPage = (int) $request->input('per_page', 10);
        $paginated = $this->service->listBatches($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Lấy chi tiết một đợt import và danh sách lỗi
     */
    public function showBatch(string $id): JsonResponse
    {
        try {
            $batch = $this->service->getBatchDetail($id);

            return response()->json([
                'success' => true,
                'data' => $batch,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt import này: '.$e->getMessage(),
            ], 404);
        }
    }
}
