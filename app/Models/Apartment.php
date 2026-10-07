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

    protected $appends = [
        'primary_owner',
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
     * Thông tin chủ hộ hoặc đại diện cư dân
     *
     * @return array{full_name: string, phone_number: ?string, email: ?string}|null
     */
    public function getPrimaryOwnerAttribute(): ?array
    {
        if ($this->relationLoaded('headOfHousehold') && $this->headOfHousehold && $this->headOfHousehold->user) {
            return [
                'full_name' => $this->headOfHousehold->user->full_name,
                'phone_number' => $this->headOfHousehold->user->phone_number,
                'email' => $this->headOfHousehold->user->email,
            ];
        }

        if ($this->relationLoaded('currentResident') && $this->currentResident) {
            return [
                'full_name' => $this->currentResident->full_name,
                'phone_number' => $this->currentResident->phone_number,
                'email' => $this->currentResident->email,
            ];
        }

        if ($this->relationLoaded('firstResident') && $this->firstResident && $this->firstResident->user) {
            return [
                'full_name' => $this->firstResident->user->full_name,
                'phone_number' => $this->firstResident->user->phone_number,
                'email' => $this->firstResident->user->email,
            ];
        }

        return null;
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
     * Cư dân đầu tiên đang hoạt động trong căn hộ (fallback)
     */
    public function firstResident(): HasOne
    {
        return $this->hasOne(Resident::class, 'apartment_id')
            ->where('is_active', 1)
            ->oldest();
    }

    /**
     * Người đại diện hiện tại của căn hộ
     */
    public function currentResident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_resident_user_id');
    }

    /**
     * Khối tòa nhà chứa căn hộ (Quan hệ N-1: Apartment -> Block)
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    /**
     * Tầng chứa căn hộ (Quan hệ N-1: Apartment -> Floor)
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id');
    }
}
