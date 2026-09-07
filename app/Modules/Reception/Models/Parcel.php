<?php

namespace App\Modules\Reception\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Bưu phẩm.
 * Quản lý Check-in (tiếp nhận) / Check-out (giao cho cư dân).
 */
class Parcel extends Model
{
    use HasFactory;

    protected $fillable = [
        'resident_id',
        'sender',
        'tracking_number',
        'description',
        'received_at',
        'picked_up_at',
        'received_by',
        'picked_up_by',
        'status',
        'notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'picked_up_at' => 'datetime',
    ];

    const STATUS_RECEIVED = 'received';
    const STATUS_NOTIFIED = 'notified';
    const STATUS_PICKED_UP = 'picked_up';
    const STATUS_RETURNED = 'returned';

    /**
     * Cư dân nhận bưu phẩm.
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * Kiểm tra bưu phẩm chưa được lấy.
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_NOTIFIED]);
    }
}
