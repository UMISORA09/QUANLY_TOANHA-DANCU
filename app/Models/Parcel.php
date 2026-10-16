<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parcel extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'parcels';

    protected $fillable = [
        'tracking_number',
        'courier_company',
        'apartment_id',
        'recipient_user_id',
        'recipient_name',
        'recipient_phone',
        'parcel_photo_url',
        'ai_ocr_extracted_text',
        'ai_ocr_raw_result',
        'ai_ocr_confidence',
        'stored_location_type',
        'locker_compartment_id',
        'pickup_pin_code',
        'pickup_qr_code',
        'status',
        'received_at',
        'received_by_staff_id',
        'collected_at',
        'collected_by_name',
        'collected_signature_url',
        'checkout_staff_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ai_ocr_confidence' => 'decimal:2',
            'received_at' => 'datetime',
            'collected_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Chuẩn hóa URL ảnh bưu phẩm về relative path /storage/parcels/... để hiển thị được trên mọi domain/port
     */
    public function getParcelPhotoUrlAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (preg_match('#/storage/parcels/[^"\'\s]+#', $value, $matches)) {
            return $matches[0];
        }

        if (str_starts_with($value, 'parcels/')) {
            return '/storage/'.$value;
        }

        return $value;
    }

    /**
     * Căn hộ nhận bưu phẩm
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    /**
     * Tài khoản người dùng cư dân nhận hàng (nếu có)
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * Nhân viên lễ tân tiếp nhận bưu phẩm
     */
    public function receivedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_staff_id');
    }

    /**
     * Nhân viên thực hiện bàn giao / check-out bưu phẩm
     */
    public function checkoutStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checkout_staff_id');
    }

    /**
     * Scope: Bưu phẩm chờ nhận tại quầy hoặc locker
     */
    public function scopePendingReception(Builder $query): Builder
    {
        return $query->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED']);
    }

    /**
     * Scope: Bưu phẩm tồn đọng quá hạn (> $days ngày chưa nhận)
     */
    public function scopeOverdue(Builder $query, int $days = 5): Builder
    {
        return $query->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
            ->where('received_at', '<', now()->subDays($days));
    }
}
