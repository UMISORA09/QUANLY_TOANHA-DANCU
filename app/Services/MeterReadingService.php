<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\MeterReadingBatch;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
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
        $cacheKey = 'meter_summary_'.$billingCycle.'_'.($blockId ?? 'all');

        return Cache::remember($cacheKey, 6, function () use ($billingCycle, $blockId) {
            $meterQuery = Meter::query()->where('is_active', true);
            if ($blockId) {
                $meterQuery->whereHas('apartment', fn ($q) => $q->where('block_id', $blockId));
            }
            $totalMeters = $meterQuery->count();

            $readingQuery = DB::table('meter_readings')
                ->join('meters', 'meters.id', '=', 'meter_readings.meter_id')
                ->where('meter_readings.billing_cycle', $billingCycle);

            if ($blockId) {
                $readingQuery->join('apartments', 'apartments.id', '=', 'meter_readings.apartment_id')
                    ->where('apartments.block_id', $blockId);
            }

            $stats = $readingQuery->selectRaw("
                COUNT(*) as recorded_meters,
                SUM(CASE WHEN meter_readings.is_abnormal_consumption = 1 THEN 1 ELSE 0 END) as abnormal_count,
                SUM(CASE WHEN meters.meter_type = 'ELECTRICITY' THEN meter_readings.consumed_units ELSE 0 END) as elec_units,
                SUM(CASE WHEN meters.meter_type IN ('WATER', 'COLD_WATER') THEN meter_readings.consumed_units ELSE 0 END) as water_units,
                MAX(CASE WHEN meter_readings.is_locked_for_billing = 1 THEN 1 ELSE 0 END) as is_locked
            ")->first();

            $recordedMeters = (int) ($stats->recorded_meters ?? 0);
            $pendingMeters = max(0, $totalMeters - $recordedMeters);
            $completionRate = $totalMeters > 0 ? round(($recordedMeters / $totalMeters) * 100, 1) : 0.0;
            $abnormalCount = (int) ($stats->abnormal_count ?? 0);
            $elecUnits = (float) ($stats->elec_units ?? 0);
            $waterUnits = (float) ($stats->water_units ?? 0);
            $isCycleLocked = (bool) ($stats->is_locked ?? 0);

            return [
                'billing_cycle' => $billingCycle,
                'total_meters' => $totalMeters,
                'recorded_meters' => $recordedMeters,
                'pending_meters' => $pendingMeters,
                'completion_rate' => $completionRate,
                'abnormal_count' => $abnormalCount,
                'total_electricity_kwh' => round($elecUnits, 2),
                'total_water_m3' => round($waterUnits, 2),
                'is_cycle_locked' => $isCycleLocked,
            ];
        });
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

        $existing = MeterReading::where('meter_id', $meter->id)
            ->where('billing_cycle', $cycle)
            ->first();
        if ($existing && $existing->is_locked_for_billing) {
            throw ValidationException::withMessages([
                'reading' => "Chỉ số kỳ {$cycle} của đồng hồ này đã bị khóa sổ, không thể chỉnh sửa.",
            ]);
        }

        $result = DB::transaction(function () use (
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

        $this->clearSummaryCache($cycle, $meter->apartment?->block_id);

        return $result;
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

        $this->clearSummaryCache($reading->billing_cycle, $reading->apartment?->block_id);

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

        $cycle = $reading->billing_cycle;
        $blockId = $reading->apartment?->block_id;
        $deleted = (bool) $reading->delete();

        $this->clearSummaryCache($cycle, $blockId);

        return $deleted;
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

        $updated = $query->update(['is_locked_for_billing' => true]);

        $this->clearSummaryCache($billingCycle, $blockId);

        return $updated;
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

        $updated = $query->update(['is_locked_for_billing' => false]);

        $this->clearSummaryCache($billingCycle, $blockId);

        return $updated;
    }

    /**
     * Xóa cache thống kê kỳ chốt số tức thời
     */
    public function clearSummaryCache(string $billingCycle, ?string $blockId = null): void
    {
        Cache::forget('meter_summary_'.$billingCycle.'_all');
        if ($blockId) {
            Cache::forget('meter_summary_'.$billingCycle.'_'.$blockId);
        } else {
            try {
                $blockIds = Block::pluck('id');
                foreach ($blockIds as $bId) {
                    Cache::forget('meter_summary_'.$billingCycle.'_'.$bId);
                }
            } catch (\Throwable) {
                // Ignore fallback
            }
        }
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
            // Tự động điền trước chỉ số cũ/hiện tại để người dùng có thể tải về và import thử ngay mà không bị lỗi để trống
            $curr = $existingReading ? $existingReading->current_reading : $prev;

            $typeName = match ($m->meter_type) {
                'ELECTRICITY' => 'Điện (kWh)',
                'WATER' => 'Nước (m³)',
                default => $m->meter_type,
            };

            $row = [
                $stt++,
                $m->meter_code,
                $m->apartment?->apartment_number ?? '',
                $m->apartment?->block?->block_name ?? '',
                $m->apartment?->floor?->floor_name ?? '',
                $typeName,
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
     * Sinh biểu mẫu Excel (.xls) có định dạng chuyên nghiệp: logo thương hiệu,
     * bảng khung form chỉn chu, màu sắc nhận diện, hướng dẫn nhập liệu và highlight cột nhập số liệu.
     */
    public function generateExcelTemplate(string $cycle, ?string $blockId = null, ?string $meterType = null): string
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

        $blockModel = $blockId ? Block::find($blockId) : null;
        $blockName = $blockModel ? "{$blockModel->block_name} ({$blockModel->block_code})" : 'Toàn bộ tòa nhà / Tất cả phân khu';

        $cycleCarbon = Carbon::createFromFormat('Y-m', $cycle);
        $suggestedDate = $cycleCarbon->endOfMonth()->toDateString();
        $exportDate = Carbon::now()->format('d/m/Y H:i');
        $totalMeters = count($meters);

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $html .= '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
        $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Chỉ Số Điện Nước</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
        $html .= '<style>';
        $html .= 'body, table { font-family: "Segoe UI", Arial, Helvetica, sans-serif; font-size: 10pt; color: #0F172A; }';
        $html .= '.company-box { background-color: #FFFFFF; border: 1px solid #CBD5E1; padding: 10px 14px; vertical-align: middle; }';
        $html .= '.doc-meta-box { background-color: #F8FAFC; border: 1px solid #CBD5E1; padding: 10px 14px; text-align: right; vertical-align: middle; font-size: 8.5pt; color: #475569; }';
        $html .= '.brand-title { background-color: #0F172A; color: #38BDF8; font-size: 13pt; font-weight: bold; text-align: center; height: 38px; vertical-align: middle; letter-spacing: 0.5px; border: 1px solid #0F172A; }';
        $html .= '.sub-title { background-color: #0284C7; color: #FFFFFF; font-size: 10.5pt; font-weight: bold; text-align: center; height: 28px; vertical-align: middle; border: 1px solid #0284C7; }';
        $html .= '.meta-lbl { background-color: #F1F5F9; color: #334155; font-weight: bold; font-size: 9.5pt; border: 1px solid #CBD5E1; padding: 6px 10px; }';
        $html .= '.meta-val { background-color: #FFFFFF; color: #0F172A; font-size: 9.5pt; border: 1px solid #CBD5E1; padding: 6px 10px; }';
        $html .= '.guide-box { background-color: #FEF9C3; color: #854D0E; font-size: 9pt; border: 2px dashed #F59E0B; padding: 10px 14px; line-height: 1.5; }';
        $html .= 'th.hdr { background-color: #0F172A; color: #FFFFFF; font-weight: bold; font-size: 9.5pt; text-align: center; vertical-align: middle; border: 1px solid #334155; height: 36px; padding: 5px; }';
        $html .= 'th.hdr-target { background-color: #D97706; color: #FFFFFF; font-weight: bold; font-size: 10pt; text-align: center; vertical-align: middle; border: 2px solid #B45309; height: 36px; padding: 5px; }';
        $html .= 'td.cell-stt { border: 1px solid #CBD5E1; text-align: center; background-color: #F8FAFC; color: #64748B; padding: 6px 4px; }';
        $html .= 'td.cell-code { border: 1px solid #CBD5E1; text-align: center; font-family: Consolas, monospace; font-weight: bold; color: #0284C7; padding: 6px 8px; mso-number-format: "\@"; }';
        $html .= 'td.cell-apt { border: 1px solid #CBD5E1; text-align: center; font-weight: bold; color: #0F172A; padding: 6px 8px; mso-number-format: "\@"; }';
        $html .= 'td.cell-text { border: 1px solid #CBD5E1; padding: 6px 8px; }';
        $html .= 'td.cell-center { border: 1px solid #CBD5E1; text-align: center; padding: 6px 8px; }';
        $html .= 'td.cell-prev { border: 1px solid #CBD5E1; text-align: right; color: #475569; background-color: #F8FAFC; font-weight: 500; padding: 6px 10px; mso-number-format: "#\,##0\.0"; }';
        $html .= 'td.cell-curr { border: 2px solid #F59E0B; background-color: #FEFCE8; text-align: right; font-weight: bold; color: #0F172A; padding: 6px 10px; mso-number-format: "#\,##0\.0"; }';
        $html .= 'td.cell-date { border: 1px solid #CBD5E1; text-align: center; color: #475569; padding: 6px 8px; mso-number-format: "yyyy-mm-dd"; }';
        $html .= 'tr.alt td:not(.cell-curr) { background-color: #F8FAFC; }';
        $html .= '.footer-total { background-color: #F1F5F9; font-weight: bold; color: #0F172A; border: 1px solid #94A3B8; padding: 8px 10px; }';
        $html .= '.sig-title { font-weight: bold; text-align: center; font-size: 10pt; color: #0F172A; padding-top: 15px; }';
        $html .= '.sig-sub { font-style: italic; text-align: center; font-size: 8.5pt; color: #64748B; }';
        $html .= '</style></head><body>';

        $html .= '<table border="1" cellpadding="0" cellspacing="0" style="border-collapse: collapse; border: 1px solid #CBD5E1;">';

        // 1. Dòng Logo & Thông tin công ty quản lý tòa nhà
        $html .= '<tr>';
        $html .= '<td colspan="6" class="company-box" style="border-top: 3px solid #0284C7;">';
        $html .= '<div style="font-size: 13pt; font-weight: bold; color: #0284C7; letter-spacing: 0.5px;">🏢 SMART CASSAVAS • BUILDING MANAGEMENT</div>';
        $html .= '<div style="font-size: 10pt; font-weight: bold; color: #0F172A; margin-top: 2px;">CÔNG TY CỔ PHẦN CÔNG NGHỆ & QUẢN LÝ BẤT ĐỘNG SẢN SMART CASSAVAS</div>';
        $html .= '<div style="font-size: 8.5pt; color: #64748B; margin-top: 2px;">Hệ thống quản lý tòa nhà & cư dân số hóa • Hotline: 1900 8888 • Email: bql@smartcassavas.vn</div>';
        $html .= '</td>';
        $html .= '<td colspan="5" class="doc-meta-box" style="border-top: 3px solid #0284C7;">';
        $html .= '<div><b>MÃ BIỂU MẪU:</b> BM-QLTN/05-CSDN</div>';
        $html .= '<div style="margin-top: 2px;"><b>QUY TRÌNH:</b> ISO 9001:2015 QUẢN LÝ VẬN HÀNH</div>';
        $html .= '<div style="margin-top: 2px; color: #0284C7; font-weight: bold;">KỲ ĐỐI SOÁT: '.htmlspecialchars($cycle).'</div>';
        $html .= '</td>';
        $html .= '</tr>';

        // 2. Banner Tiêu Đề Biểu Mẫu
        $html .= '<tr><th colspan="11" class="brand-title">BẢNG KÊ KHAI & CHỐT CHỈ SỐ ĐIỆN - NƯỚC HÀNG LOẠT</th></tr>';
        $html .= '<tr><th colspan="11" class="sub-title">KỲ THU PHÍ: '.htmlspecialchars($cycle).' — BIỂU MẪU ĐỐI SOÁT & NHẬP LIỆU TỰ ĐỘNG</th></tr>';

        // 3. Khung Thông Tin Biểu Mẫu (2 dòng metadata chỉnh chu)
        $html .= '<tr>';
        $html .= '<td colspan="2" class="meta-lbl">Tòa Nhà / Khối:</td>';
        $html .= '<td colspan="4" class="meta-val"><b>'.htmlspecialchars($blockName).'</b></td>';
        $html .= '<td colspan="2" class="meta-lbl">Ngày Xuất Biểu Mẫu:</td>';
        $html .= '<td colspan="3" class="meta-val">'.htmlspecialchars($exportDate).'</td>';
        $html .= '</tr>';

        $html .= '<tr>';
        $html .= '<td colspan="2" class="meta-lbl">Tổng Số Công Tơ:</td>';
        $html .= '<td colspan="4" class="meta-val"><b style="color: #0284C7;">'.$totalMeters.'</b> điểm đo kỹ thuật</td>';
        $html .= '<td colspan="2" class="meta-lbl">Hạn Chót Chốt Số:</td>';
        $html .= '<td colspan="3" class="meta-val"><b style="color: #D97706;">'.htmlspecialchars($suggestedDate).'</b></td>';
        $html .= '</tr>';

        // 4. Khung Hướng Dẫn & Lưu Ý Kỹ Thuật
        $html .= '<tr><td colspan="11" class="guide-box">';
        $html .= '<b>📌 QUY ĐỊNH & HƯỚNG DẪN NHẬP DỮ LIỆU:</b><br>';
        $html .= '• Cột <b>[Chỉ Số Kỳ Này (*)]</b> (tô viền vàng) đã được điền sẵn chỉ số kỳ trước. Kỹ thuật viên kiểm tra và cập nhật chỉ số mới thực tế tăng lên.<br>';
        $html .= '• Nếu để trống ô <b>[Chỉ Số Kỳ Này (*)]</b>, hệ thống sẽ tự động gán bằng chỉ số kỳ trước (Tiêu thụ = 0) và không báo lỗi.<br>';
        $html .= '• <b>Không xóa, đổi tên hoặc sửa thứ tự</b> cột [Mã Đồng Hồ] và [Số Căn Hộ] để hệ thống đối soát tự động chính xác.<br>';
        $html .= '• Trường hợp căn hộ thay công tơ mới bắt đầu lại từ 0, nhập số <b>1</b> tại cột [Thay Đồng Hồ Mới (1/0)].';
        $html .= '</td></tr>';

        // Dòng cách nhẹ nhàng
        $html .= '<tr><td colspan="11" style="height: 12px; background-color: #FFFFFF; border: none;"></td></tr>';

        // 5. Header Bảng Dữ Liệu
        $html .= '<tr>';
        $html .= '<th class="hdr" style="width: 50px;">STT</th>';
        $html .= '<th class="hdr" style="width: 140px;">Mã Đồng Hồ</th>';
        $html .= '<th class="hdr" style="width: 95px;">Số Căn Hộ</th>';
        $html .= '<th class="hdr" style="width: 160px;">Khối Tòa Nhà</th>';
        $html .= '<th class="hdr" style="width: 80px;">Tầng</th>';
        $html .= '<th class="hdr" style="width: 115px;">Loại Dịch Vụ</th>';
        $html .= '<th class="hdr" style="width: 125px;">Chỉ Số Kỳ Trước</th>';
        $html .= '<th class="hdr-target" style="width: 150px;">Chỉ Số Kỳ Này (*)</th>';
        $html .= '<th class="hdr" style="width: 125px;">Ngày Ghi Số (YYYY-MM-DD)</th>';
        $html .= '<th class="hdr" style="width: 125px;">Thay Đồng Hồ Mới (1/0)</th>';
        $html .= '<th class="hdr" style="width: 160px;">Ghi Chú</th>';
        $html .= '</tr>';

        // 6. Dữ liệu từng dòng
        $stt = 1;
        $totalPrev = 0;
        $totalCurr = 0;
        foreach ($meters as $m) {
            $existingReading = $m->readings->first();
            $prev = $existingReading ? (float) $existingReading->previous_reading : (float) $m->current_reading;
            $curr = $existingReading ? (float) $existingReading->current_reading : $prev;
            $totalPrev += $prev;
            $totalCurr += $curr;

            $typeName = match ($m->meter_type) {
                'ELECTRICITY' => 'Điện (kWh)',
                'WATER' => 'Nước (m³)',
                default => $m->meter_type,
            };

            $rowClass = ($stt % 2 === 0) ? ' class="alt"' : '';

            $html .= '<tr'.$rowClass.'>';
            $html .= '<td class="cell-stt">'.$stt++.'</td>';
            $html .= '<td class="cell-code">'.htmlspecialchars($m->meter_code).'</td>';
            $html .= '<td class="cell-apt">'.htmlspecialchars($m->apartment?->apartment_number ?? '').'</td>';
            $html .= '<td class="cell-text">'.htmlspecialchars($m->apartment?->block?->block_name ?? '').'</td>';
            $html .= '<td class="cell-center">'.htmlspecialchars($m->apartment?->floor?->floor_name ?? '').'</td>';
            $html .= '<td class="cell-center"><b>'.htmlspecialchars($typeName).'</b></td>';
            $html .= '<td class="cell-prev">'.$prev.'</td>';
            $html .= '<td class="cell-curr">'.$curr.'</td>';
            $html .= '<td class="cell-date">'.htmlspecialchars($suggestedDate).'</td>';
            $html .= '<td class="cell-center">0</td>';
            $html .= '<td class="cell-text">'.htmlspecialchars($existingReading?->abnormal_reason ?? '').'</td>';
            $html .= '</tr>';
        }

        // 7. Dòng Tổng Kết Biểu Mẫu
        $html .= '<tr>';
        $html .= '<td colspan="6" class="footer-total" style="text-align: right;">TỔNG CỘNG ('.$totalMeters.' ĐỒNG HỒ):</td>';
        $html .= '<td class="footer-total" style="text-align: right; color: #475569; mso-number-format: \'#\,##0\.0\';">'.$totalPrev.'</td>';
        $html .= '<td class="footer-total" style="text-align: right; color: #B45309; background-color: #FEF3C7; mso-number-format: \'#\,##0\.0\';">'.$totalCurr.'</td>';
        $html .= '<td colspan="3" class="footer-total"></td>';
        $html .= '</tr>';

        // 8. Khung Chữ Ký & Xác Nhận Phê Duyệt (3 bên)
        $html .= '<tr><td colspan="11" style="height: 20px; background-color: #FFFFFF; border: none;"></td></tr>';
        $html .= '<tr>';
        $html .= '<td colspan="4" class="sig-title" style="border: none;">NGƯỜI LẬP BIỂU</td>';
        $html .= '<td colspan="3" class="sig-title" style="border: none;">KỸ THUẬT VIÊN GHI SỐ</td>';
        $html .= '<td colspan="4" class="sig-title" style="border: none;">TRƯỞNG BAN QUẢN LÝ</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td colspan="4" class="sig-sub" style="border: none;">(Ký và ghi rõ họ tên)</td>';
        $html .= '<td colspan="3" class="sig-sub" style="border: none;">(Ký và ghi rõ họ tên)</td>';
        $html .= '<td colspan="4" class="sig-sub" style="border: none;">(Ký tên và đóng dấu)</td>';
        $html .= '</tr>';
        $html .= '<tr><td colspan="11" style="height: 60px; background-color: #FFFFFF; border: none;"></td></tr>';
        $html .= '<tr>';
        $html .= '<td colspan="4" class="sig-sub" style="border: none;">Ngày ..... tháng ..... năm 202...</td>';
        $html .= '<td colspan="3" class="sig-sub" style="border: none;">Ngày ..... tháng ..... năm 202...</td>';
        $html .= '<td colspan="4" class="sig-sub" style="border: none;">Ngày ..... tháng ..... năm 202...</td>';
        $html .= '</tr>';

        $html .= '</table></body></html>';

        return $html;
    }

    /**
     * Import danh sách chỉ số điện nước hàng loạt từ file CSV / Excel (.xls / .xlsx text)
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

        $isHtml = str_contains(strtolower($cleanContent), '<table') || str_contains(strtolower($cleanContent), '<tr');

        $parsedRows = [];
        if ($isHtml) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $cleanContent, $trMatches);
            foreach ($trMatches[1] as $trHtml) {
                preg_match_all('/<(?:td|th)[^>]*>(.*?)<\/(?:td|th)>/is', $trHtml, $cellMatches);
                if (! empty($cellMatches[1])) {
                    $rowCells = array_map(function ($c) {
                        return trim(html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }, $cellMatches[1]);
                    if (! empty(array_filter($rowCells, fn ($c) => $c !== ''))) {
                        $parsedRows[] = $rowCells;
                    }
                }
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', trim($cleanContent));
            if (! empty($lines)) {
                $firstLine = $lines[0];
                $delimiter = ',';
                if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                    $delimiter = ';';
                } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
                    $delimiter = "\t";
                }
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $parsedRows[] = str_getcsv($trimmed, $delimiter);
                    }
                }
            }
        }

        if (empty($parsedRows) || count($parsedRows) < 2) {
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

        // Tìm dòng header chứa mã đồng hồ
        $headerRowIdx = null;
        $headerMap = [];
        foreach ($parsedRows as $rIdx => $row) {
            if (count($row) < 3) {
                continue;
            }

            $hasMeterCode = false;
            $hasOtherCol = false;
            foreach ($row as $cIdx => $cellValue) {
                $norm = Str::lower(Str::ascii(trim($cellValue)));
                if (strlen($norm) < 40 && (str_contains($norm, 'ma dong ho') || str_contains($norm, 'meter_code'))) {
                    $hasMeterCode = true;
                }
                if (str_contains($norm, 'stt') || str_contains($norm, 'can ho') || str_contains($norm, 'chi so')) {
                    $hasOtherCol = true;
                }
            }

            if ($hasMeterCode && $hasOtherCol) {
                $headerRowIdx = $rIdx;
                break;
            }
        }

        if ($headerRowIdx === null) {
            throw ValidationException::withMessages([
                'file' => 'Không tìm thấy dòng tiêu đề chứa cột "Mã Đồng Hồ" trong tệp tải lên.',
            ]);
        }

        $headerRow = $parsedRows[$headerRowIdx];
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
        for ($i = $headerRowIdx + 1; $i < count($parsedRows); $i++) {
            $row = $parsedRows[$i];
            if (empty(array_filter($row, fn ($c) => trim($c) !== ''))) {
                continue;
            }

            $rowCombined = Str::lower(Str::ascii(implode(' ', $row)));
            if (
                str_contains($rowCombined, 'tong cong') ||
                str_contains($rowCombined, 'nguoi lap') ||
                str_contains($rowCombined, 'ky thuat vien') ||
                str_contains($rowCombined, 'truong ban') ||
                str_contains($rowCombined, 'chu ky') ||
                str_contains($rowCombined, 'ky va ghi ro') ||
                str_contains($rowCombined, 'ky ten va dong dau') ||
                str_contains($rowCombined, 'ngay .....') ||
                str_contains($rowCombined, 'ngay ...')
            ) {
                continue;
            }

            $meterCode = isset($row[$meterCodeIdx]) ? trim($row[$meterCodeIdx]) : '';
            if ($meterCode === '') {
                continue;
            }

            $lineIndex = $i + 1; // Số dòng trên Excel (1-based)
            $totalRecords++;

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

            // Xác định previous_reading
            $prevReading = (is_numeric($rawPrev) && $rawPrev !== '')
                ? (float) $rawPrev
                : (float) $meter->current_reading;

            // Validation 3: Chỉ số mới (nếu để trống tự động lấy chỉ số cũ tiêu thụ = 0)
            if ($rawCurrent === '' || $rawCurrent === null) {
                $currentReading = $prevReading;
            } elseif (! is_numeric($rawCurrent)) {
                $failedRecords++;
                $errors[] = [
                    'line' => $lineIndex,
                    'meter_code' => $meterCode,
                    'apartment' => $meter->apartment?->apartment_number ?? $aptNumber,
                    'error' => "Chỉ số mới '{$rawCurrent}' không phải là số hợp lệ.",
                ];

                continue;
            } else {
                $currentReading = (float) $rawCurrent;
            }

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
