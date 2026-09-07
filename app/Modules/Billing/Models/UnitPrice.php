<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model Đơn giá.
 * Cài đặt đơn giá cho từng loại phí (điện, nước, dịch vụ).
 */
class UnitPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'label',
        'price_per_unit',
        'unit',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'price_per_unit' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Lấy đơn giá hiện hành theo loại.
     */
    public function scopeCurrentPrice($query, string $type)
    {
        return $query->where('type', $type)
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->latest('effective_from');
    }
}
