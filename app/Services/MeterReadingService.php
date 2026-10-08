<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Meter;
use App\Models\MeterReading;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
}
