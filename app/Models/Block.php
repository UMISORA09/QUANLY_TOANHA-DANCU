<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Block extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'blocks';

    protected $fillable = [
        'block_code',
        'block_name',
        'total_floors',
        'total_basements',
        'total_apartments',
        'status',
        'address_line',
        'hotline_phone',
        'description',
        'building_manager_user_id',
        'ai_features_enabled',
        'metadata',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'total_floors' => 'integer',
            'total_basements' => 'integer',
            'total_apartments' => 'integer',
            'ai_features_enabled' => 'boolean',
            'version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Danh sách các tầng thuộc khối tòa nhà (Quan hệ 1-N: Block -> Floors)
     */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class, 'block_id')->orderBy('floor_number', 'asc');
    }

    /**
     * Danh sách tất cả căn hộ thuộc khối tòa nhà (Quan hệ 1-N: Block -> Apartments)
     */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class, 'block_id');
    }

    /**
     * Quản lý tòa nhà phụ trách
     */
    public function buildingManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'building_manager_user_id');
    }
}
