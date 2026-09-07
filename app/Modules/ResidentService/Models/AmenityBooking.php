<?php

namespace App\Modules\ResidentService\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Đặt chỗ tiện ích.
 */
class AmenityBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'amenity_id',
        'resident_id',
        'booking_date',
        'time_slot_start',
        'time_slot_end',
        'status',
        'notes',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'time_slot_start' => 'datetime:H:i',
        'time_slot_end' => 'datetime:H:i',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class);
    }
}
