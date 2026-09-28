<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryRegistration extends Model
{
    use HasFactory, HasUuids;

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
