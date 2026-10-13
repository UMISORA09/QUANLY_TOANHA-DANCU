<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReading extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'meter_readings';

    protected $fillable = [
        'meter_id',
        'apartment_id',
        'batch_id',
        'billing_cycle',
        'period_start_date',
        'period_end_date',
        'previous_reading',
        'current_reading',
        'consumed_units',
        'reading_source',
        'recorded_by_user_id',
        'meter_photo_url',
        'ai_detected_reading',
        'ai_confidence_score',
        'is_abnormal_consumption',
        'abnormal_reason',
        'is_locked_for_billing',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'previous_reading' => 'float',
            'current_reading' => 'float',
            'consumed_units' => 'float',
            'ai_detected_reading' => 'float',
            'ai_confidence_score' => 'float',
            'is_abnormal_consumption' => 'boolean',
            'is_locked_for_billing' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class, 'meter_id');
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MeterReadingBatch::class, 'batch_id');
    }

    public function scopeInCycle($query, string $cycle)
    {
        return $query->where('billing_cycle', $cycle);
    }

    public function scopeAbnormal($query)
    {
        return $query->where('is_abnormal_consumption', true);
    }

    public function scopeLocked($query)
    {
        return $query->where('is_locked_for_billing', true);
    }
}
