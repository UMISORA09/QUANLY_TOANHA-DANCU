<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class VisitorRegistration extends Model
{
    use HasFactory, Searchable;

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
     * Tên search index
     */
    public function searchableAs(): string
    {
        return 'visitor_registrations';
    }

    /**
     * Dữ liệu được index vào Search Engine (Meilisearch)
     * TUYỆT ĐỐI KHÔNG index mã QR truy cập bảo mật (qr_access_pass_code)
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['hostUser', 'apartment.block']);

        return [
            'id' => (string) $this->id,
            'registration_code' => $this->registration_code,
            'host_resident_user_id' => (string) $this->host_resident_user_id,
            'apartment_id' => (string) $this->apartment_id,
            'visitor_name' => $this->visitor_name,
            'visitor_phone' => $this->visitor_phone ?? '',
            'visit_purpose' => $this->visit_purpose ?? '',
            'visitor_count' => (int) $this->visitor_count,
            'vehicle_license_plate' => $this->vehicle_license_plate ?? '',
            'qr_pass_status' => $this->qr_pass_status,
            'is_pre_approved_by_resident' => (bool) $this->is_pre_approved_by_resident,
            'host_name' => $this->hostUser?->full_name ?? '',
            'host_phone' => $this->hostUser?->phone_number ?? '',
            'apartment_number' => $this->apartment?->apartment_number ?? '',
            'block_name' => $this->apartment?->block?->block_name ?? '',
            'expected_arrival_time' => $this->expected_arrival_time?->timestamp,
            'expected_departure_time' => $this->expected_departure_time?->timestamp,
            'created_at' => $this->created_at?->timestamp,
        ];
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

    /**
     * Lịch sử các lần check-in / check-out của lượt khách này
     */
    public function checkinLogs(): HasMany
    {
        return $this->hasMany(VisitorCheckinLog::class, 'registration_id');
    }

    /**
     * Lượt check-in mới nhất
     */
    public function latestCheckinLog(): HasOne
    {
        return $this->hasOne(VisitorCheckinLog::class, 'registration_id')->latestOfMany('checkin_time');
    }
}
