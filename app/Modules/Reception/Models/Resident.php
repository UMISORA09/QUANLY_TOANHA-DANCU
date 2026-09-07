<?php

namespace App\Modules\Reception\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Cư dân.
 */
class Resident extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'apartment_id',
        'full_name',
        'email',
        'phone',
        'id_card',
        'date_of_birth',
        'gender',
        'move_in_date',
        'move_out_date',
        'is_owner',
        'status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'move_in_date' => 'date',
        'move_out_date' => 'date',
        'is_owner' => 'boolean',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_MOVED_OUT = 'moved_out';

    /**
     * Căn hộ của cư dân.
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /**
     * Bưu phẩm của cư dân.
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }

    /**
     * Khách viếng thăm cư dân.
     */
    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }
}
