<?php

namespace App\Modules\Reception\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Khách viếng thăm.
 */
class Visitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'resident_id',
        'full_name',
        'id_card',
        'phone',
        'purpose',
        'check_in_at',
        'check_out_at',
        'vehicle_plate',
        'notes',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    /**
     * Cư dân được viếng thăm.
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * Kiểm tra khách còn đang ở trong tòa nhà.
     */
    public function isCurrentlyVisiting(): bool
    {
        return $this->check_in_at !== null && $this->check_out_at === null;
    }
}
