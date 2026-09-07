<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Hóa đơn.
 * Lưu thông tin hóa đơn hàng tháng cho từng căn hộ/cư dân.
 */
class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'resident_id',
        'apartment_id',
        'month',
        'year',
        'total_amount',
        'status',
        'due_date',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    /**
     * Trạng thái hóa đơn.
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_OVERDUE = 'overdue';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Các chi tiết hóa đơn.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Kiểm tra hóa đơn đã quá hạn chưa.
     */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->due_date->isPast();
    }
}
