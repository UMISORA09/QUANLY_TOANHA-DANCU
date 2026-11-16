<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VisitorCheckinLog extends Model
{
    use HasFactory;

    protected $table = 'visitor_checkin_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'registration_id',
        'apartment_id',
        'visitor_name',
        'visitor_phone',
        'national_id_number',
        'id_card_photo_url',
        'ai_ocr_extracted_data',
        'checkin_time',
        'checkout_time',
        'receptionist_user_id',
        'security_guard_user_id',
        'temp_card_id',
        'assigned_elevator_access',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'checkin_time' => 'datetime',
        'checkout_time' => 'datetime',
        'created_at' => 'datetime',
        'ai_ocr_extracted_data' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Lượt đăng ký khách ban đầu của cư dân (nếu có khai báo trước)
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(VisitorRegistration::class, 'registration_id');
    }

    /**
     * Căn hộ đón tiếp khách
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    /**
     * Lễ tân phụ trách check-in
     */
    public function receptionist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receptionist_user_id');
    }

    /**
     * Nhân viên an ninh phụ trách kiểm soát
     */
    public function securityGuard(): BelongsTo
    {
        return $this->belongsTo(User::class, 'security_guard_user_id');
    }
}
