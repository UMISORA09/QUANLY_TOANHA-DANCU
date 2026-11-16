<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'invoice_items';

    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id',
        'service_code',
        'item_description',
        'meter_reading_id',
        'previous_reading',
        'current_reading',
        'quantity',
        'unit_name',
        'unit_price',
        'amount_before_tax',
        'vat_percentage',
        'vat_amount',
        'environmental_fee_amount',
        'total_line_amount',
        'tier_calculation_details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_reading' => 'float',
            'current_reading' => 'float',
            'quantity' => 'float',
            'unit_price' => 'float',
            'amount_before_tax' => 'float',
            'vat_percentage' => 'float',
            'vat_amount' => 'float',
            'environmental_fee_amount' => 'float',
            'total_line_amount' => 'float',
            'tier_calculation_details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Hóa đơn chứa khoản mục này
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Bản ghi chỉ số công tơ liên kết (nếu là mục điện/nước)
     */
    public function meterReading(): BelongsTo
    {
        return $this->belongsTo(MeterReading::class, 'meter_reading_id');
    }
}
