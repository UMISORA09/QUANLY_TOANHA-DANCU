<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meter extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'meters';

    protected $fillable = [
        'meter_code',
        'meter_type',
        'apartment_id',
        'iot_device_id',
        'installation_date',
        'initial_reading',
        'current_reading',
        'last_reading_date',
        'multiplier_factor',
        'calibration_due_date',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_reading' => 'float',
            'current_reading' => 'float',
            'multiplier_factor' => 'float',
            'installation_date' => 'date',
            'last_reading_date' => 'date',
            'calibration_due_date' => 'date',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class, 'meter_id');
    }

    public function latestReading(): HasOne
    {
        return $this->hasOne(MeterReading::class, 'meter_id')->latestOfMany('created_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('meter_type', $type);
    }
}
