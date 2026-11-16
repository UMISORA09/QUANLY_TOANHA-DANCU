<?php

namespace App\Services;

use App\Models\PricingTier;
use App\Models\ServicePricingConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ServicePricingService
{
    /**
     * Lấy danh sách cấu hình đơn giá có lọc và phân loại
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ServicePricingConfig>
     */
    public function listConfigs(array $filters = []): Collection
    {
        $query = ServicePricingConfig::with('tiers');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('service_code', 'like', "%{$search}%")
                    ->orWhere('service_name', 'like', "%{$search}%");
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        if (! empty($filters['billing_type'])) {
            $query->where('billing_type', $filters['billing_type']);
        }

        if (! empty($filters['meter_type'])) {
            $query->where('meter_type', $filters['meter_type']);
        }

        return $query->orderBy('service_code', 'asc')->get();
    }

    /**
     * Lấy chi tiết một cấu hình theo ID
     */
    public function getConfig(string $id): ServicePricingConfig
    {
        $config = ServicePricingConfig::with('tiers')->find($id);

        if (! $config) {
            throw new RuntimeException("Không tìm thấy cấu hình đơn giá với mã: {$id}");
        }

        return $config;
    }

    /**
     * Tạo mới cấu hình đơn giá kèm bậc thang (nếu có)
     *
     * @param  array<string, mixed>  $data
     */
    public function createConfig(array $data): ServicePricingConfig
    {
        return DB::transaction(function () use ($data) {
            $serviceCode = strtoupper(trim((string) $data['service_code']));

            $exists = ServicePricingConfig::where('service_code', $serviceCode)->exists();
            if ($exists) {
                throw new InvalidArgumentException("Mã loại dịch vụ '{$serviceCode}' đã tồn tại trong hệ thống.");
            }

            $tiersData = $data['tiers'] ?? null;
            unset($data['tiers']);

            $data['id'] = $data['id'] ?? (string) Str::uuid();
            $data['service_code'] = $serviceCode;
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : true;
            $data['fixed_unit_price'] = (float) ($data['fixed_unit_price'] ?? 0);
            $data['vat_percentage'] = (float) ($data['vat_percentage'] ?? 10.00);
            $data['environmental_protection_fee_pct'] = (float) ($data['environmental_protection_fee_pct'] ?? 0.00);
            $data['effective_from_date'] = $data['effective_from_date'] ?? now()->format('Y-m-d');

            $config = ServicePricingConfig::create($data);

            if (is_array($tiersData) && count($tiersData) > 0) {
                $this->syncTiers($config->id, $tiersData);
            }

            return $config->fresh('tiers');
        });
    }

    /**
     * Cập nhật thông tin cấu hình đơn giá
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConfig(string $id, array $data): ServicePricingConfig
    {
        return DB::transaction(function () use ($id, $data) {
            $config = $this->getConfig($id);

            if (! empty($data['service_code'])) {
                $newCode = strtoupper(trim((string) $data['service_code']));
                if ($newCode !== $config->service_code) {
                    $exists = ServicePricingConfig::where('service_code', $newCode)
                        ->where('id', '!=', $id)
                        ->exists();
                    if ($exists) {
                        throw new InvalidArgumentException("Mã loại dịch vụ '{$newCode}' đã được sử dụng bởi cấu hình khác.");
                    }
                    $data['service_code'] = $newCode;
                }
            }

            $tiersData = $data['tiers'] ?? null;
            unset($data['tiers']);

            $config->update($data);

            if (is_array($tiersData)) {
                $this->syncTiers($config->id, $tiersData);
            }

            return $config->fresh('tiers');
        });
    }

    /**
     * Bật / tắt trạng thái kích hoạt cấu hình
     */
    public function toggleActive(string $id): ServicePricingConfig
    {
        $config = $this->getConfig($id);
        $config->is_active = ! $config->is_active;
        $config->save();

        return $config;
    }

    /**
     * Xóa mềm cấu hình đơn giá
     */
    public function deleteConfig(string $id): bool
    {
        $config = $this->getConfig($id);

        return (bool) $config->delete();
    }

    /**
     * Lấy danh sách bậc thang của cấu hình
     *
     * @return Collection<int, PricingTier>
     */
    public function getTiers(string $configId): Collection
    {
        $config = $this->getConfig($configId);

        return $config->tiers;
    }

    /**
     * Cập nhật và chuẩn hóa danh sách các bậc thang định mức lũy tiến
     *
     * @param  array<int, array<string, mixed>>  $tiersData
     * @return Collection<int, PricingTier>
     */
    public function syncTiers(string $configId, array $tiersData): Collection
    {
        if (empty($tiersData)) {
            throw new InvalidArgumentException('Danh sách bậc thang định mức không được để trống.');
        }

        // Sắp xếp các bậc theo tier_order tăng dần
        usort($tiersData, function ($a, $b) {
            $orderA = (int) ($a['tier_order'] ?? 0);
            $orderB = (int) ($b['tier_order'] ?? 0);

            return $orderA <=> $orderB;
        });

        // Kiểm tra tính liên tục và hợp lệ của các bậc
        $totalTiers = count($tiersData);
        $previousMax = 0.0;

        foreach ($tiersData as $index => $tier) {
            $expectedOrder = $index + 1;
            $order = (int) ($tier['tier_order'] ?? $expectedOrder);
            if ($order !== $expectedOrder) {
                throw new InvalidArgumentException("Thứ tự bậc thang không liên tục (bậc thứ {$expectedOrder} có số thứ tự {$order}).");
            }

            $min = (float) ($tier['min_usage_threshold'] ?? 0);
            $max = isset($tier['max_usage_threshold']) && $tier['max_usage_threshold'] !== null && $tier['max_usage_threshold'] !== ''
                ? (float) $tier['max_usage_threshold']
                : null;
            $unitPrice = (float) ($tier['unit_price'] ?? 0);

            if ($unitPrice < 0) {
                throw new InvalidArgumentException("Đơn giá của Bậc {$order} không được là số âm.");
            }

            if ($index === 0 && $min != 0.0) {
                throw new InvalidArgumentException('Ngưỡng tiêu thụ tối thiểu của Bậc 1 bắt buộc phải bắt đầu từ 0.');
            }

            if ($index > 0 && abs($min - $previousMax) > 0.001) {
                throw new InvalidArgumentException("Khoảng bậc thang không hợp lệ: Ngưỡng bắt đầu Bậc {$order} ({$min}) phải bằng ngưỡng kết thúc Bậc ".($order - 1)." ({$previousMax}).");
            }

            if ($max !== null && $max <= $min) {
                throw new InvalidArgumentException("Ngưỡng tiêu thụ tối đa của Bậc {$order} ({$max}) phải lớn hơn ngưỡng tối thiểu ({$min}).");
            }

            if ($max === null && $index < $totalTiers - 1) {
                throw new InvalidArgumentException('Chỉ bậc thang cuối cùng mới được để trống ngưỡng tối đa (vô hạn).');
            }

            $previousMax = (float) $max;
        }

        return DB::transaction(function () use ($configId, $tiersData) {
            // Xóa các bậc thang cũ
            PricingTier::where('pricing_config_id', $configId)->delete();

            $now = now();
            $newTiers = [];

            foreach ($tiersData as $index => $tier) {
                $order = $index + 1;
                $tierName = ! empty($tier['tier_name'])
                    ? trim((string) $tier['tier_name'])
                    : "Bậc {$order}";

                $min = (float) $tier['min_usage_threshold'];
                $max = isset($tier['max_usage_threshold']) && $tier['max_usage_threshold'] !== null && $tier['max_usage_threshold'] !== ''
                    ? (float) $tier['max_usage_threshold']
                    : null;

                $newTiers[] = PricingTier::create([
                    'id' => (string) Str::uuid(),
                    'pricing_config_id' => $configId,
                    'tier_order' => $order,
                    'tier_name' => $tierName,
                    'min_usage_threshold' => $min,
                    'max_usage_threshold' => $max,
                    'unit_price' => (float) $tier['unit_price'],
                    'created_at' => $now,
                ]);
            }

            return new Collection($newTiers);
        });
    }

    /**
     * Mô phỏng chiết tính hóa đơn theo lượng tiêu thụ
     *
     * @return array<string, mixed>
     */
    public function simulateCalculation(string $configId, float $usage): array
    {
        $config = $this->getConfig($configId);

        return $config->calculateCost($usage);
    }
}
