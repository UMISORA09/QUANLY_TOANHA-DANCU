<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Floor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'floors';

    protected $fillable = [
        'block_id',
        'floor_number',
        'floor_code',
        'floor_name',
        'floor_type',
        'total_units',
        'floor_plan_image_url',
    ];

    protected function casts(): array
    {
        return [
            'floor_number' => 'integer',
            'total_units' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Khối tòa nhà chứa tầng này.
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    /**
     * Các căn hộ nằm trên tầng này.
     */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class, 'floor_id');
    }
}
