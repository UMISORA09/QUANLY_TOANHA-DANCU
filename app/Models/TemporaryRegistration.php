<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class TemporaryRegistration extends Model
{
    use HasFactory, HasUuids, Searchable;

    protected $table = 'temporary_registrations';

    protected $fillable = [
        'resident_id',
        'apartment_id',
        'registration_type',
        'start_date',
        'end_date',
        'reason',
        'police_status',
        'police_reference_code',
        'identity_card_front_url',
        'identity_card_back_url',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Chuẩn hóa URL ảnh CCCD đảm bảo không lộ đường dẫn tĩnh công khai
     */
    protected function normalizeCccdUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        // Chuyển đổi các URL tĩnh cũ dạng /storage/temporary_registrations/cccd/... sang endpoint bảo mật
        if (str_starts_with($value, '/storage/temporary_registrations/cccd/')) {
            $filename = basename($value);

            return '/api/v1/residents/temporary-registrations/cccd/'.$filename;
        }

        if (str_starts_with($value, 'temporary_registrations/cccd/')) {
            $filename = basename($value);

            return '/api/v1/residents/temporary-registrations/cccd/'.$filename;
        }

        return $value;
    }

    protected function identityCardFrontUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->normalizeCccdUrl($value),
        );
    }

    protected function identityCardBackUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->normalizeCccdUrl($value),
        );
    }

    /**
     * Tên search index
     */
    public function searchableAs(): string
    {
        return 'temporary_registrations';
    }

    /**
     * Dữ liệu được index vào Search Engine (Meilisearch)
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['resident.user', 'apartment.block']);

        return [
            'id' => (string) $this->id,
            'resident_id' => (string) $this->resident_id,
            'apartment_id' => (string) $this->apartment_id,
            'registration_type' => $this->registration_type,
            'police_status' => $this->police_status,
            'police_reference_code' => $this->police_reference_code ?? '',
            'resident_name' => $this->resident?->user?->full_name ?? '',
            'resident_phone' => $this->resident?->user?->phone_number ?? '',
            'apartment_number' => $this->apartment?->apartment_number ?? '',
            'block_name' => $this->apartment?->block?->block_name ?? '',
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'reason' => $this->reason ?? '',
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Cư dân làm thủ tục đăng ký tạm trú / tạm vắng
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id');
    }

    /**
     * Căn hộ lưu trú
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    /**
     * Cán bộ / Quản trị viên duyệt hồ sơ
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
