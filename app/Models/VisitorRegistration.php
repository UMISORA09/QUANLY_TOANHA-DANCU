<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VisitorRegistration extends Model
{
    use HasFactory;

    protected $table = 'visitor_registrations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'registration_code',
        'host_resident_user_id',
        'apartment_id',
        'visitor_name',
        'visitor_phone',
        'visitor_national_id',
        'expected_arrival_time',
        'expected_departure_time',
        'visit_purpose',
        'visitor_count',
        'vehicle_license_plate',
        'qr_access_pass_code',
        'qr_pass_status',
        'is_pre_approved_by_resident',
    ];

    protected $casts = [
        'expected_arrival_time' => 'datetime',
        'expected_departure_time' => 'datetime',
        'is_pre_approved_by_resident' => 'boolean',
        'visitor_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Cư dân tạo lượt khai báo đón khách
     */
    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_resident_user_id');
    }

    /**
     * Căn hộ đón tiếp khách
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }
}
