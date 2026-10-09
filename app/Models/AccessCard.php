<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessCard extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'access_cards';

    protected $fillable = [
        'card_uid',
        'card_number',
        'card_type',
        'assigned_user_id',
        'assigned_apartment_id',
        'assigned_vehicle_id',
        'issued_date',
        'expiry_date',
        'status',
        'deposit_fee',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'deposit_fee' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Cư dân / Người dùng được cấp thẻ
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Căn hộ thẻ được gán
     */
    public function assignedApartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'assigned_apartment_id');
    }

    /**
     * Phương tiện liên kết (nếu là thẻ gửi xe)
     */
    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    /**
     * Scope lọc các thẻ đang kích hoạt (ACTIVE)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Kiểm tra xem thẻ có đang kích hoạt hay không
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
