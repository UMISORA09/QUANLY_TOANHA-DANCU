<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Chi tiết hóa đơn.
 * Lưu từng dòng phí trong hóa đơn (điện, nước, dịch vụ, ...).
 */
class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'type',
        'description',
        'quantity',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    /**
     * Loại phí.
     */
    const TYPE_ELECTRIC = 'electric';
    const TYPE_WATER = 'water';
    const TYPE_SERVICE = 'service';
    const TYPE_PARKING = 'parking';
    const TYPE_OTHER = 'other';

    /**
     * Hóa đơn chứa item này.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
