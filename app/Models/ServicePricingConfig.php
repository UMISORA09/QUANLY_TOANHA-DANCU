<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServicePricingConfig extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'service_pricing_configs';

    protected $fillable = [
        'service_code',
        'service_name',
        'meter_type',
        'billing_type',
        'unit_name',
        'fixed_unit_price',
        'vat_percentage',
        'environmental_protection_fee_pct',
        'effective_from_date',
        'effective_to_date',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fixed_unit_price' => 'float',
            'vat_percentage' => 'float',
            'environmental_protection_fee_pct' => 'float',
            'effective_from_date' => 'date:Y-m-d',
            'effective_to_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Bậc thang giá định mức (nếu tính lũy tiến)
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(PricingTier::class, 'pricing_config_id')
            ->orderBy('tier_order', 'asc');
    }

    /**
     * Thuật toán tính toán chiết tính tiền theo sản lượng tiêu thụ / diện tích
     *
     * @param  float  $usage  Sản lượng kWh / m3 nước / m2 diện tích
     * @return array{
     *     usage: float,
     *     subtotal: float,
     *     vat_percentage: float,
     *     vat_amount: float,
     *     environmental_protection_fee_pct: float,
     *     environmental_fee: float,
     *     total_amount: float,
     *     tier_breakdowns: array<int, array{
     *         tier_order: int,
     *         tier_name: string,
     *         min_usage: float,
     *         max_usage: ?float,
     *         unit_price: float,
     *         tier_usage: float,
     *         tier_amount: float
     *     }>
     * }
     */
    public function calculateCost(float $usage): array
    {
        $usage = max(0.0, $usage);
        $subtotal = 0.0;
        $tierBreakdowns = [];

        if ($this->billing_type === 'TIERED_USAGE') {
            $sortedTiers = $this->tiers->sortBy('tier_order')->values();
            $remainingUsage = $usage;

            foreach ($sortedTiers as $tier) {
                $min = (float) $tier->min_usage_threshold;
                $max = $tier->max_usage_threshold !== null ? (float) $tier->max_usage_threshold : null;
                $unitPrice = (float) $tier->unit_price;

                $tierCapacity = $max !== null ? max(0.0, $max - $min) : PHP_FLOAT_MAX;
                $tierUsage = 0.0;

                if ($usage > $min) {
                    if ($max !== null) {
                        $tierUsage = min($usage - $min, $tierCapacity);
                    } else {
                        $tierUsage = $usage - $min;
                    }
                }

                $tierAmount = round($tierUsage * $unitPrice, 2);
                $subtotal += $tierAmount;

                $tierBreakdowns[] = [
                    'tier_order' => (int) $tier->tier_order,
                    'tier_name' => (string) $tier->tier_name,
                    'min_usage' => $min,
                    'max_usage' => $max,
                    'unit_price' => $unitPrice,
                    'tier_usage' => round($tierUsage, 2),
                    'tier_amount' => $tierAmount,
                ];
            }
        } elseif ($this->billing_type === 'FIXED_MONTHLY') {
            $subtotal = (float) $this->fixed_unit_price;
        } else {
            // UNIT_PRICE_USAGE hoặc tính theo m2
            $subtotal = round($usage * (float) $this->fixed_unit_price, 2);
        }

        $vatPct = (float) $this->vat_percentage;
        $vatAmount = round($subtotal * ($vatPct / 100), 2);

        $envPct = (float) $this->environmental_protection_fee_pct;
        $envAmount = round($subtotal * ($envPct / 100), 2);

        $totalAmount = round($subtotal + $vatAmount + $envAmount, 2);

        return [
            'usage' => $usage,
            'subtotal' => $subtotal,
            'vat_percentage' => $vatPct,
            'vat_amount' => $vatAmount,
            'environmental_protection_fee_pct' => $envPct,
            'environmental_fee' => $envAmount,
            'total_amount' => $totalAmount,
            'tier_breakdowns' => $tierBreakdowns,
        ];
    }
}
