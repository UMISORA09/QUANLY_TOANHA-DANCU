<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\MeterReadingBatch;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MeterReadingService
{
    /**
     * Thống kê tổng quan chu kỳ ghi chỉ số
     *
     * @return array{
     *     billing_cycle: string,
     *     total_meters: int,
     *     recorded_meters: int,
     *     pending_meters: int,
     *     completion_rate: float,
     *     abnormal_count: int,
     *     total_electricity_kwh: float,
     *     total_water_m3: float,
     *     is_cycle_locked: bool
     * }
     */
    public function getSummary(string $billingCycle, ?string $blockId = null): array
    {
        $meterQuery = Meter::query()->where('is_active', true);
        if ($blockId) {
            $meterQuery->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
        }
        $totalMeters = $meterQuery->count();

        $readingQuery = MeterReading::query()
            ->where('billing_cycle', $billingCycle);

        if ($blockId) {
            $readingQuery->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
        }

        $recordedMeters = (clone $readingQuery)->count();
        $pendingMeters = max(0, $totalMeters - $recordedMeters);
        $completionRate = $totalMeters > 0 ? round(($recordedMeters / $totalMeters) * 100, 1) : 0.0;

        $abnormalCount = (clone $readingQuery)->where('is_abnormal_consumption', true)->count();

        $elecUnits = (clone $readingQuery)
            ->whereHas('meter', fn ($q) => $q->where('meter_type', 'ELECTRICITY'))
            ->sum('consumed_units');

        $waterUnits = (clone $readingQuery)
            ->whereHas('meter', fn ($q) => $q->whereIn('meter_type', ['WATER', 'COLD_WATER']))
            ->sum('consumed_units');

        $isCycleLocked = (clone $readingQuery)->where('is_locked_for_billing', true)->exists();

        return [
            'billing_cycle' => $billingCycle,
            'total_meters' => $totalMeters,
            'recorded_meters' => $recordedMeters,
            'pending_meters' => $pendingMeters,
            'completion_rate' => $completionRate,
            'abnormal_count' => $abnormalCount,
            'total_electricity_kwh' => round((float) $elecUnits, 2),
            'total_water_m3' => round((float) $waterUnits, 2),
            'is_cycle_locked' => $isCycleLocked,
        ];
    }

    /**
     * Danh sách đồng hồ kèm chỉ số ghi nhận trong kỳ (nếu có)
     */
    public function listMeters(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $cycle = $filters['cycle'] ?? Carbon::now()->format('Y-m');

        $query = Meter::query()
            ->with([
                'apartment.block',
                'apartment.floor',
                'readings' => fn ($q) => $q->where('billing_cycle', $cycle),
            ])
            ->where('is_active', true);

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('meter_code', 'like', "%{$search}%")
                    ->orWhereHas('apartment', fn ($sq) => $sq->where('apartment_number', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['meter_type'])) {
            $query->where('meter_type', $filters['meter_type']);
        }

        if (! empty($filters['block_id'])) {
            $query->whereHas('apartment', fn ($q) => $q->where('block_id', $filters['block_id']));
        }

        if (! empty($filters['floor_id'])) {
            $query->whereHas('apartment', fn ($q) => $q->where('floor_id', $filters['floor_id']));
        }

        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        if (isset($filters['recorded_status'])) {
            if ($filters['recorded_status'] === 'recorded') {
                $query->whereHas('readings', fn ($q) => $q->where('billing_cycle', $cycle));
            } elseif ($filters['recorded_status'] === 'pending') {
                $query->whereDoesntHave('readings', fn ($q) => $q->where('billing_cycle', $cycle));
            }
        }

        return $query->orderBy('meter_code', 'asc')->paginate($perPage);
    }

    /**
     * Danh sách lịch sử chỉ số ghi nhận
     */
    public function listReadings(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = MeterReading::query()
            ->with([
                'meter',
                'apartment.block',
                'apartment.floor',
                'recordedBy',
            ]);

        if (! empty($filters['cycle'])) {
            $query->where('billing_cycle', $filters['cycle']);
        }

        if (! empty($filters['meter_type'])) {
            $query->whereHas('meter', fn ($q) => $q->where('meter_type', $filters['meter_type']));
        }

        if (! empty($filters['block_id'])) {
            $query->whereHas('apartment', fn ($q) => $q->where('block_id', $filters['block_id']));
        }

        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereHas('meter', fn ($mq) => $mq->where('meter_code', 'like', "%{$search}%"))
                    ->orWhereHas('apartment', fn ($aq) => $aq->where('apartment_number', 'like', "%{$search}%"));
            });
        }

        if (isset($filters['is_abnormal']) && $filters['is_abnormal'] !== '') {
            $query->where('is_abnormal_consumption', filter_var($filters['is_abnormal'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Khai báo đồng hồ đo mới cho căn hộ
     */
    public function createMeter(array $data): Meter
    {
        $apartment = Apartment::findOrFail($data['apartment_id']);

        if (empty($data['meter_code'])) {
            $typePrefix = $data['meter_type'] === 'ELECTRICITY' ? 'EM' : 'WM';
            $data['meter_code'] = "{$typePrefix}-{$apartment->apartment_number}-".strtoupper(substr(uniqid(), -4));
        }

        $existing = Meter::where('meter_code', $data['meter_code'])->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'meter_code' => "Mã đồng hồ {$data['meter_code']} đã tồn tại trên hệ thống.",
            ]);
        }

        return Meter::create([
            'meter_code' => $data['meter_code'],
            'meter_type' => $data['meter_type'],
            'apartment_id' => $apartment->id,
            'installation_date' => $data['installation_date'] ?? Carbon::now()->toDateString(),
            'initial_reading' => (float) ($data['initial_reading'] ?? 0.0),
            'current_reading' => (float) ($data['initial_reading'] ?? 0.0),
            'multiplier_factor' => (float) ($data['multiplier_factor'] ?? 1.0),
            'calibration_due_date' => $data['calibration_due_date'] ?? null,
            'is_active' => true,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Chốt chỉ số đo thủ công qua form (Quản lý / Kỹ thuật viên)
     */
    public function recordManualReading(array $data, ?string $userId = null): MeterReading
    {
        $meter = Meter::with('apartment')->findOrFail($data['meter_id']);
        $cycle = $data['billing_cycle'] ?? Carbon::now()->format('Y-m');

        // Kiểm tra xem kỳ này đã bị khóa sổ chưa
        $existing = MeterReading::where('meter_id', $meter->id)
            ->where('billing_cycle', $cycle)
            ->first();

        if ($existing && $existing->is_locked_for_billing) {
            throw ValidationException::withMessages([
                'billing_cycle' => "Kỳ {$cycle} của đồng hồ này đã bị khóa sổ, không thể điều chỉnh.",
            ]);
        }

        $currentReading = (float) $data['current_reading'];

        // Xác định chỉ số cũ
        if (isset($data['previous_reading']) && is_numeric($data['previous_reading'])) {
            $prevReading = (float) $data['previous_reading'];
        } elseif ($existing) {
            $prevReading = (float) $existing->previous_reading;
        } else {
            // Lấy từ kỳ trước gần nhất
            $prevRecord = MeterReading::where('meter_id', $meter->id)
                ->where('billing_cycle', '<', $cycle)
                ->orderByDesc('billing_cycle')
                ->first();

            $prevReading = $prevRecord ? (float) $prevRecord->current_reading : (float) $meter->current_reading;
        }

        $forceReset = ! empty($data['force_reset']);

        if ($currentReading < $prevReading && ! $forceReset) {
            throw ValidationException::withMessages([
                'current_reading' => "Chỉ số mới ({$currentReading}) không thể nhỏ hơn chỉ số cũ ({$prevReading}). Nếu vừa thay đồng hồ, vui lòng chọn xác nhận 'Thay mới đồng hồ'.",
            ]);
        }

        $multiplier = $meter->multiplier_factor > 0 ? $meter->multiplier_factor : 1.0;
        $consumed = $forceReset ? $currentReading * $multiplier : ($currentReading - $prevReading) * $multiplier;
        $consumed = max(0, round($consumed, 2));

        // Phân tích bất thường
        $isAbnormal = false;
        $abnormalReason = null;

        $historyAvg = MeterReading::where('meter_id', $meter->id)
            ->where('billing_cycle', '<', $cycle)
            ->avg('consumed_units');

        if ($historyAvg !== null && $historyAvg > 0) {
            $historyAvg = (float) $historyAvg;
            if ($consumed >= $historyAvg * 2.5 && $consumed >= 50) {
                $isAbnormal = true;
                $percent = round((($consumed - $historyAvg) / $historyAvg) * 100);
                $abnormalReason = "Lượng tiêu thụ tăng vọt +{$percent}% so với trung bình các kỳ trước ({$historyAvg}).";
            }
        }

        // Ngưỡng trần đột biến tuyệt đối
        if ($meter->meter_type === 'ELECTRICITY' && $consumed >= 600) {
            $isAbnormal = true;
            $abnormalReason = $abnormalReason ?: "Sản lượng điện tiêu thụ cao bất thường ({$consumed} kWh >= 600 kWh).";
        } elseif (in_array($meter->meter_type, ['WATER', 'COLD_WATER']) && $consumed >= 50) {
            $isAbnormal = true;
            $abnormalReason = $abnormalReason ?: "Lượng nước tiêu thụ cao bất thường ({$consumed} m³ >= 50 m³ - nghi vấn rò rỉ).";
        }

        $cycleCarbon = Carbon::createFromFormat('Y-m', $cycle);
        $periodStart = $data['period_start_date'] ?? $cycleCarbon->copy()->startOfMonth()->toDateString();
        $periodEnd = $data['period_end_date'] ?? $cycleCarbon->copy()->endOfMonth()->toDateString();

        return DB::transaction(function () use (
            $meter,
            $cycle,
            $periodStart,
            $periodEnd,
            $prevReading,
            $currentReading,
            $consumed,
            $userId,
            $data,
            $isAbnormal,
            $abnormalReason
        ) {
            $reading = MeterReading::updateOrCreate(
                [
                    'meter_id' => $meter->id,
                    'billing_cycle' => $cycle,
                ],
                [
                    'apartment_id' => $meter->apartment_id,
                    'period_start_date' => $periodStart,
                    'period_end_date' => $periodEnd,
                    'previous_reading' => $prevReading,
                    'current_reading' => $currentReading,
                    'consumed_units' => $consumed,
                    'reading_source' => $data['reading_source'] ?? 'MANUAL',
                    'recorded_by_user_id' => $userId,
                    'meter_photo_url' => $data['meter_photo_url'] ?? null,
                    'ai_detected_reading' => isset($data['ai_detected_reading']) ? (float) $data['ai_detected_reading'] : null,
                    'ai_confidence_score' => isset($data['ai_confidence_score']) ? (float) $data['ai_confidence_score'] : null,
                    'is_abnormal_consumption' => $isAbnormal,
                    'abnormal_reason' => $abnormalReason,
                    'is_locked_for_billing' => false,
                ]
            );

            // Cập nhật meter current_reading
            $meter->update([
                'current_reading' => $currentReading,
                'last_reading_date' => $periodEnd,
            ]);

            return $reading->load(['meter', 'apartment.block', 'apartment.floor']);
        });
    }

    /**
     * Chỉnh sửa bản ghi chỉ số đo
     */
    public function updateReading(string $readingId, array $data): MeterReading
    {
        $reading = MeterReading::with('meter')->findOrFail($readingId);

        if ($reading->is_locked_for_billing) {
            throw ValidationException::withMessages([
                'reading' => 'Bản ghi chỉ số này đã bị khóa sổ để lập hóa đơn, không thể chỉnh sửa.',
            ]);
        }

        $meter = $reading->meter;
        $currentReading = isset($data['current_reading']) ? (float) $data['current_reading'] : $reading->current_reading;
        $prevReading = isset($data['previous_reading']) ? (float) $data['previous_reading'] : $reading->previous_reading;

        if ($currentReading < $prevReading && empty($data['force_reset'])) {
            throw ValidationException::withMessages([
                'current_reading' => "Chỉ số mới ({$currentReading}) không thể nhỏ hơn chỉ số cũ ({$prevReading}).",
            ]);
        }

        $multiplier = $meter && $meter->multiplier_factor > 0 ? $meter->multiplier_factor : 1.0;
        $consumed = max(0, round(($currentReading - $prevReading) * $multiplier, 2));

        $reading->update([
            'current_reading' => $currentReading,
            'previous_reading' => $prevReading,
            'consumed_units' => $consumed,
            'meter_photo_url' => $data['meter_photo_url'] ?? $reading->meter_photo_url,
            'is_abnormal_consumption' => $data['is_abnormal_consumption'] ?? $reading->is_abnormal_consumption,
            'abnormal_reason' => $data['abnormal_reason'] ?? $reading->abnormal_reason,
        ]);

        if ($meter) {
            $meter->update([
                'current_reading' => $currentReading,
            ]);
        }

        return $reading->load(['meter', 'apartment.block', 'apartment.floor']);
    }

    /**
     * Xóa bản ghi chỉ số đo
     */
    public function deleteReading(string $readingId): bool
    {
        $reading = MeterReading::findOrFail($readingId);

        if ($reading->is_locked_for_billing) {
            throw ValidationException::withMessages([
                'reading' => 'Bản ghi chỉ số này đã bị khóa sổ, không thể xóa.',
            ]);
        }

        return (bool) $reading->delete();
    }

    /**
     * Khóa sổ kỳ chốt chỉ số
     */
    public function lockCycle(string $billingCycle, ?string $blockId = null): int
    {
        $query = MeterReading::where('billing_cycle', $billingCycle);
        if ($blockId) {
            $query->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
        }

        return $query->update(['is_locked_for_billing' => true]);
    }

    /**
     * Mở khóa sổ kỳ chốt chỉ số
     */
    public function unlockCycle(string $billingCycle, ?string $blockId = null): int
    {
        $query = MeterReading::where('billing_cycle', $billingCycle);
        if ($blockId) {
            $query->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
        }

        return $query->update(['is_locked_for_billing' => false]);
    }

    /**
     * Sinh file mẫu CSV có sẵn danh sách đồng hồ và chỉ số cũ của các căn hộ
     */
    public function generateTemplate(string $cycle, ?string $blockId = null, ?string $meterType = null): string
    {
        $query = Meter::query()
            ->with(['apartment.block', 'apartment.floor', 'readings' => fn ($q) => $q->where('billing_cycle', $cycle)])
            ->where('is_active', true);

        if ($blockId) {
            $query->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
        }

        if ($meterType && $meterType !== 'ALL') {
            $query->where('meter_type', $meterType);
        }

        $meters = $query->orderBy('meter_code')->get();

        // Thêm UTF-8 BOM để Excel tự động nhận diện tiếng Việt có dấu chuẩn xác
        $output = "\xEF\xBB\xBF";
        $headers = [
            'STT',
            'Mã Đồng Hồ',
            'Số Căn Hộ',
            'Khối Tòa Nhà',
            'Tầng',
            'Loại Dịch Vụ',
            'Chỉ Số Kỳ Trước',
            'Chỉ Số Kỳ Này (*)',
            'Ngày Ghi Số (YYYY-MM-DD)',
            'Thay Đồng Hồ Mới (1/0)',
            'Ghi Chú',
        ];

        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, $headers);

        $cycleCarbon = Carbon::createFromFormat('Y-m', $cycle);
        $suggestedDate = $cycleCarbon->endOfMonth()->toDateString();

        $stt = 1;
        foreach ($meters as $m) {
            $existingReading = $m->readings->first();
            $prev = $existingReading ? $existingReading->previous_reading : $m->current_reading;
            $curr = $existingReading ? $existingReading->current_reading : '';

            $row = [
                $stt++,
                $m->meter_code,
                $m->apartment?->apartment_number ?? '',
                $m->apartment?->block?->block_name ?? '',
                $m->apartment?->floor?->floor_name ?? '',
                $m->meter_type,
                $prev,
                $curr,
                $suggestedDate,
                '0',
                $existingReading?->abnormal_reason ?? '',
            ];
            fputcsv($handle, $row);
        }

        rewind($handle);
        $output .= stream_get_contents($handle);
        fclose($handle);

        return $output;
    }

    /**
     * Import danh sách chỉ số điện nước hàng loạt từ file CSV / Excel text
     */
    public function importFromCsv(
        string $filePath,
        string $originalFileName,
        string $cycle,
        ?string $blockId = null,
        ?string $meterType = null,
        ?string $userId = null
    ): MeterReadingBatch {
        // 1. Kiểm tra kỳ này đã bị khóa sổ chưa
        $lockedQuery = MeterReading::where('billing_cycle', $cycle)->where('is_locked_for_billing', true);
        if ($blockId) {
            $lockedQuery->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
        }
        if ($lockedQuery->exists()) {
            throw ValidationException::withMessages([
                'billing_cycle' => "Kỳ {$cycle} đã bị khóa sổ để lập hóa đơn, không thể import dữ liệu.",
            ]);
        }

        if (! file_exists($filePath)) {
            throw new \RuntimeException('Không tìm thấy tệp tải lên để xử lý.');
        }

        $rawContent = file_get_contents($filePath);
        // Xóa UTF-8 BOM nếu có
        $cleanContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);
        $lines = preg_split('/\r\n|\r|\n/', trim($cleanContent));

        if (empty($lines) || count($lines) < 2) {
            throw ValidationException::withMessages([
                'file' => 'Tệp dữ liệu trống hoặc không có dòng dữ liệu hợp lệ.',
            ]);
        }

        // Tạo Batch record
        $batchCode = 'BATCH-'.str_replace('-', '', $cycle).'-'.strtoupper(Str::random(6));
        $batch = MeterReadingBatch::create([
            'batch_code' => $batchCode,
            'billing_month_year' => $cycle,
            'meter_type' => $meterType ?: 'ALL',
            'block_id' => $blockId,
            'file_name' => $originalFileName,
            'file_url' => $filePath,
            'uploaded_by' => $userId ?: '00000000-0000-0000-0000-000000000000',
            'total_records' => 0,
            'success_records' => 0,
            'failed_records' => 0,
            'import_status' => 'PROCESSING',
            'error_summary_json' => [],
        ]);

        // Xác định delimiter (phẩy, chấm phẩy, tab)
        $firstLine = $lines[0];
        $delimiter = ',';
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }

        $headerRow = str_getcsv($firstLine, $delimiter);
        $headerMap = [];
        foreach ($headerRow as $idx => $colName) {
            $normalized = Str::lower(Str::ascii(trim($colName)));
            if (str_contains($normalized, 'ma dong ho') || str_contains($normalized, 'meter_code')) {
                $headerMap['meter_code'] = $idx;
            } elseif (str_contains($normalized, 'chi so ky nay') || str_contains($normalized, 'chi so moi') || str_contains($normalized, 'current_reading')) {
                $headerMap['current_reading'] = $idx;
            } elseif (str_contains($normalized, 'chi so ky truoc') || str_contains($normalized, 'chi so cu') || str_contains($normalized, 'previous_reading')) {
                $headerMap['previous_reading'] = $idx;
            } elseif (str_contains($normalized, 'ngay ghi') || str_contains($normalized, 'reading_date')) {
                $headerMap['reading_date'] = $idx;
            } elseif (str_contains($normalized, 'thay dong ho') || str_contains($normalized, 'force_reset')) {
                $headerMap['force_reset'] = $idx;
            } elseif (str_contains($normalized, 'ghi chu') || str_contains($normalized, 'notes')) {
                $headerMap['notes'] = $idx;
            } elseif (str_contains($normalized, 'can ho') || str_contains($normalized, 'apartment')) {
                $headerMap['apartment'] = $idx;
            }
        }

        // Fallback vị trí cột nếu không khớp header
        $meterCodeIdx = $headerMap['meter_code'] ?? 1;
        $currentReadingIdx = $headerMap['current_reading'] ?? 7;
        $prevReadingIdx = $headerMap['previous_reading'] ?? 6;
        $dateIdx = $headerMap['reading_date'] ?? 8;
        $forceResetIdx = $headerMap['force_reset'] ?? 9;
        $notesIdx = $headerMap['notes'] ?? 10;
        $aptIdx = $headerMap['apartment'] ?? 2;

        $cycleCarbon = Carbon::createFromFormat('Y-m', $cycle);
        $defaultPeriodStart = $cycleCarbon->startOfMonth()->toDateString();
        $defaultPeriodEnd = $cycleCarbon->endOfMonth()->toDateString();

        $totalRecords = 0;
        $successRecords = 0;
        $failedRecords = 0;
        $errors = [];

        // Duyệt qua từng dòng dữ liệu (bỏ header)
        for ($i = 1; $i < count($lines); $i++) {
            $lineContent = trim($lines[$i]);
            if ($lineContent === '') {
                continue;
            }

            $totalRecords++;
            $row = str_getcsv($lineContent, $delimiter);
            $lineIndex = $i + 1; // Số dòng trên Excel (1-based)

            $meterCode = isset($row[$meterCodeIdx]) ? trim($row[$meterCodeIdx]) : '';
            $rawCurrent = isset($row[$currentReadingIdx]) ? trim($row[$currentReadingIdx]) : '';
            $rawPrev = isset($row[$prevReadingIdx]) ? trim($row[$prevReadingIdx]) : '';
            $readingDate = isset($row[$dateIdx]) && trim($row[$dateIdx]) !== '' ? trim($row[$dateIdx]) : $defaultPeriodEnd;
            $forceReset = isset($row[$forceResetIdx]) && in_array(trim($row[$forceResetIdx]), ['1', 'true', 'yes', 'TRUE'], true);
            $notes = isset($row[$notesIdx]) ? trim($row[$notesIdx]) : '';
            $aptNumber = isset($row[$aptIdx]) ? trim($row[$aptIdx]) : '';

            // Validation 1: Mã công tơ không được rỗng
            if ($meterCode === '') {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => 'N/A',
                    'apartment' => $aptNumber,
                    'error' => 'Mã đồng hồ bị để trống.',
                ];

                continue;
            }

            // Validation 2: Tìm Meter
            $meter = Meter::where('meter_code', $meterCode)->first();
            if (! $meter) {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => $meterCode,
                    'apartment' => $aptNumber,
                    'error' => "Không tìm thấy đồng hồ '{$meterCode}' trên hệ thống.",
                ];

                continue;
            }

            // Validation 3: Chỉ số mới phải là số hợp lệ
            if ($rawCurrent === '' || ! is_numeric($rawCurrent)) {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => $meterCode,
                    'apartment' => $meter->apartment?->apartment_number ?? $aptNumber,
                    'error' => "Chỉ số mới '{$rawCurrent}' không hợp lệ hoặc bị để trống.",
                ];

                continue;
            }

            $currentReading = (float) $rawCurrent;
            if ($currentReading < 0) {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => $meterCode,
                    'apartment' => $meter->apartment?->apartment_number ?? $aptNumber,
                    'error' => 'Chỉ số mới không được là số âm.',
                ];

                continue;
            }

            // Xác định previous_reading
            $prevReading = (is_numeric($rawPrev) && $rawPrev !== '')
                ? (float) $rawPrev
                : (float) $meter->current_reading;

            // Validation 4: Chỉ số mới < chỉ số cũ
            if ($currentReading < $prevReading && ! $forceReset) {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => $meterCode,
                    'apartment' => $meter->apartment?->apartment_number ?? $aptNumber,
                    'error' => "Chỉ số mới ({$currentReading}) nhỏ hơn chỉ số cũ ({$prevReading}). Cần bật cờ thay mới đồng hồ nếu vừa thay công tơ.",
                ];

                continue;
            }

            // Tính lượng tiêu thụ
            $multiplier = $meter->multiplier_factor > 0 ? $meter->multiplier_factor : 1.0;
            $consumed = $forceReset ? $currentReading * $multiplier : ($currentReading - $prevReading) * $multiplier;
            $consumed = max(0, round($consumed, 2));

            // Kiểm tra bất thường
            $isAbnormal = false;
            $abnormalReason = null;

            if ($meter->meter_type === 'ELECTRICITY' && $consumed >= 600) {
                $isAbnormal = true;
                $abnormalReason = "Sản lượng điện tiêu thụ cao bất thường ({$consumed} kWh >= 600 kWh).";
            } elseif (in_array($meter->meter_type, ['WATER', 'COLD_WATER']) && $consumed >= 50) {
                $isAbnormal = true;
                $abnormalReason = "Lượng nước tiêu thụ cao bất thường ({$consumed} m³ >= 50 m³ - nghi vấn rò rỉ).";
            }

            // Lưu bản ghi vào CSDL
            try {
                DB::transaction(function () use (
                    $meter,
                    $batch,
                    $cycle,
                    $defaultPeriodStart,
                    $readingDate,
                    $prevReading,
                    $currentReading,
                    $consumed,
                    $userId,
                    $isAbnormal,
                    $abnormalReason
                ) {
                    MeterReading::updateOrCreate(
                        [
                            'meter_id' => $meter->id,
                            'billing_cycle' => $cycle,
                        ],
                        [
                            'apartment_id' => $meter->apartment_id,
                            'batch_id' => $batch->id,
                            'period_start_date' => $defaultPeriodStart,
                            'period_end_date' => $readingDate,
                            'previous_reading' => $prevReading,
                            'current_reading' => $currentReading,
                            'consumed_units' => $consumed,
                            'reading_source' => 'EXCEL_IMPORT',
                            'recorded_by_user_id' => $userId,
                            'is_abnormal_consumption' => $isAbnormal,
                            'abnormal_reason' => $abnormalReason,
                            'is_locked_for_billing' => false,
                        ]
                    );

                    $meter->update([
                        'current_reading' => $currentReading,
                        'last_reading_date' => $readingDate,
                    ]);
                });

                $successRecords++;
            } catch (\Throwable $e) {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => $meterCode,
                    'apartment' => $meter->apartment?->apartment_number ?? $aptNumber,
                    'error' => 'Lỗi lưu CSDL: '.$e->getMessage(),
                ];
            }
        }

        // Cập nhật kết quả cuối cùng của Batch
        $status = 'COMPLETED';
        if ($failedRecords > 0 && $successRecords === 0) {
            $status = 'FAILED';
        }

        $batch->update([
            'total_records' => $totalRecords,
            'success_records' => $successRecords,
            'failed_records' => $failedRecords,
            'import_status' => $status,
            'error_summary_json' => $errors,
            'completed_at' => Carbon::now(),
        ]);

        return $batch;
    }

    /**
     * Danh sách lịch sử các đợt import hàng loạt
     */
    public function listBatches(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = MeterReadingBatch::query()
            ->with(['block', 'uploader'])
            ->orderByDesc('created_at');

        if (! empty($filters['cycle'])) {
            $query->where('billing_month_year', $filters['cycle']);
        }

        if (! empty($filters['meter_type']) && $filters['meter_type'] !== 'ALL') {
            $query->where('meter_type', $filters['meter_type']);
        }

        if (! empty($filters['block_id'])) {
            $query->where('block_id', $filters['block_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Lấy thông tin chi tiết một đợt import kèm danh sách lỗi
     */
    public function getBatchDetail(string $batchId): MeterReadingBatch
    {
        return MeterReadingBatch::with(['block', 'uploader'])->findOrFail($batchId);
    }
}
