<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'vehicles';

    protected $fillable = [
        'apartment_id',
        'owner_user_id',
        'license_plate',
        'vehicle_category',
        'brand',
        'model',
        'color',
        'registration_certificate_number',
        'vehicle_photo_url',
        'registration_cert_photo_url',
        'monthly_parking_fee',
        'has_electric_charging_subscription',
        'is_active',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_parking_fee' => 'float',
            'has_electric_charging_subscription' => 'boolean',
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Căn hộ phương tiện trực thuộc
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    /**
     * Chủ sở hữu phương tiện (cư dân)
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Người phê duyệt phương tiện (quản lý / admin)
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
