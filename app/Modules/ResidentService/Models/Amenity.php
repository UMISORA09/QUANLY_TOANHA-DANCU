<?php

namespace App\Modules\ResidentService\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Tiện ích (BBQ, Gym, Pool, ...).
 */
class Amenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'description',
        'capacity',
        'location',
        'status',
        'opening_time',
        'closing_time',
    ];

    const TYPE_BBQ = 'bbq';
    const TYPE_GYM = 'gym';
    const TYPE_POOL = 'pool';
    const TYPE_MEETING_ROOM = 'meeting_room';
    const TYPE_OTHER = 'other';

    const STATUS_ACTIVE = 'active';
    const STATUS_MAINTENANCE = 'maintenance';
    const STATUS_CLOSED = 'closed';

    /**
     * Danh sách đặt chỗ của tiện ích này.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(AmenityBooking::class);
    }
}
