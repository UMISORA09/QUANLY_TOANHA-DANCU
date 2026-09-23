<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resident extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'residents';

    protected $fillable = [
        'user_id',
        'apartment_id',
        'resident_type',
        'is_head_of_household',
        'stay_start_date',
        'stay_end_date',
        'relationship_to_head',
        'occupation',
        'vehicle_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_head_of_household' => 'boolean',
            'is_active' => 'boolean',
            'stay_start_date' => 'date',
            'stay_end_date' => 'date',
            'vehicle_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Người dùng đại diện cho cư dân này
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Căn hộ cư dân đang lưu trú
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }
}
