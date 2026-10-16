<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParcelNotificationLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'parcel_notification_logs';

    protected $fillable = [
        'parcel_id',
        'recipient_user_id',
        'channel',
        'destination',
        'status',
        'attempts',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Bưu phẩm liên quan
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class, 'parcel_id');
    }

    /**
     * Cư dân nhận thông báo
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * Scope: Lấy trạng thái thành công
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'SENT');
    }
}
