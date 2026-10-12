<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingTier extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'pricing_tiers';

    /**
     * Bảng pricing_tiers trong CSDL chỉ có created_at, không có updated_at
     */
    public $timestamps = false;

    protected $fillable = [
        'pricing_config_id',
        'tier_order',
        'tier_name',
        'min_usage_threshold',
        'max_usage_threshold',
        'unit_price',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier_order' => 'integer',
            'min_usage_threshold' => 'float',
            'max_usage_threshold' => 'float',
            'unit_price' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Cấu hình biểu giá cha
     */
    public function pricingConfig(): BelongsTo
    {
        return $this->belongsTo(ServicePricingConfig::class, 'pricing_config_id');
    }
}
