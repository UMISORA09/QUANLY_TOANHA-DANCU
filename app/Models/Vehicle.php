<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Vehicle extends Model
{
    use HasFactory, HasUuids, Searchable, SoftDeletes;

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
     * Tên search index
     */
    public function searchableAs(): string
    {
        return 'vehicles';
    }

    /**
     * Dữ liệu được index vào Search Engine (Meilisearch)
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['owner', 'apartment.block']);

        return [
            'id' => (string) $this->id,
            'apartment_id' => (string) $this->apartment_id,
            'owner_user_id' => (string) $this->owner_user_id,
            'license_plate' => $this->license_plate,
            'vehicle_category' => $this->vehicle_category,
            'brand' => $this->brand ?? '',
            'model' => $this->model ?? '',
            'color' => $this->color ?? '',
            'registration_certificate_number' => $this->registration_certificate_number ?? '',
            'owner_name' => $this->owner?->full_name ?? '',
            'owner_phone' => $this->owner?->phone_number ?? '',
            'apartment_number' => $this->apartment?->apartment_number ?? '',
            'block_name' => $this->apartment?->block?->block_name ?? '',
            'monthly_parking_fee' => (float) $this->monthly_parking_fee,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->timestamp,
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
