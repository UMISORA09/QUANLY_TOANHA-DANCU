<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Resident extends Model
{
    use HasFactory, HasUuids, Searchable, SoftDeletes;

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
     * Tên search index
     */
    public function searchableAs(): string
    {
        return 'residents';
    }

    /**
     * Dữ liệu được index vào Search Engine (Meilisearch)
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['user', 'apartment.block']);

        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'apartment_id' => (string) $this->apartment_id,
            'resident_type' => $this->resident_type,
            'is_head_of_household' => (bool) $this->is_head_of_household,
            'is_active' => (bool) $this->is_active,
            'gender' => $this->user?->gender,
            'full_name' => $this->user?->full_name ?? '',
            'phone_number' => $this->user?->phone_number ?? '',
            'national_id_number' => $this->user?->national_id_number ?? '',
            'apartment_number' => $this->apartment?->apartment_number ?? '',
            'block_name' => $this->apartment?->block?->block_name ?? '',
            'block_code' => $this->apartment?->block?->block_code ?? '',
            'occupation' => $this->occupation ?? '',
            'vehicle_count' => (int) $this->vehicle_count,
            'stay_start_date' => $this->stay_start_date?->format('Y-m-d'),
            'stay_end_date' => $this->stay_end_date?->format('Y-m-d'),
            'created_at' => $this->created_at?->timestamp,
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
