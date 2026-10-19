<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtReminderLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'debt_reminder_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'invoice_id',
        'apartment_id',
        'recipient_email',
        'recipient_name',
        'reminder_type',
        'debt_amount',
        'due_date',
        'days_overdue',
        'channel_status',
        'error_message',
        'sent_at',
        'sent_by_user_id',
    ];

    protected $casts = [
        'debt_amount' => 'decimal:2',
        'due_date' => 'date',
        'days_overdue' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
