<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Apartment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'apartments';

    protected $fillable = [
        'block_id',
        'floor_id',
        'apartment_number',
        'room_type',
        'gross_floor_area_sqm',
        'net_usable_area_sqm',
        'bedroom_count',
        'bathroom_count',
        'water_quota_registered',
        'status',
        'current_resident_user_id',
        'monthly_management_fee_fixed',
        'has_balcony',
        'furnished_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_floor_area_sqm' => 'float',
            'net_usable_area_sqm' => 'float',
            'bedroom_count' => 'integer',
            'bathroom_count' => 'integer',
            'water_quota_registered' => 'integer',
            'monthly_management_fee_fixed' => 'float',
            'has_balcony' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Danh sách nhân khẩu / cư dân thuộc căn hộ
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class, 'apartment_id');
    }

    /**
     * Cư dân đang giữ vai trò Chủ hộ (active)
     */
    public function headOfHousehold(): HasOne
    {
        return $this->hasOne(Resident::class, 'apartment_id')
            ->where('is_head_of_household', 1)
            ->where('is_active', 1);
    }

    /**
     * Người đại diện hiện tại của căn hộ
     */
    public function currentResident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_resident_user_id');
    }
}
