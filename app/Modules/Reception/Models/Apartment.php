<?php

namespace App\Modules\Reception\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Căn hộ.
 */
class Apartment extends Model
{
    use HasFactory;

    protected $fillable = [
        'building',
        'floor',
        'unit_number',
        'area',
        'type',
        'status',
    ];

    protected $casts = [
        'floor' => 'integer',
        'area' => 'decimal:2',
    ];

    const STATUS_VACANT = 'vacant';
    const STATUS_OCCUPIED = 'occupied';
    const STATUS_MAINTENANCE = 'maintenance';

    const TYPE_STUDIO = 'studio';
    const TYPE_1BR = '1br';
    const TYPE_2BR = '2br';
    const TYPE_3BR = '3br';
    const TYPE_PENTHOUSE = 'penthouse';

    /**
     * Cư dân trong căn hộ.
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /**
     * Mã căn hộ đầy đủ (VD: A-12-05).
     */
    public function getFullCodeAttribute(): string
    {
        return "{$this->building}-{$this->floor}-{$this->unit_number}";
    }
}
