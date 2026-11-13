<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShiftType extends Model
{
    use HasFactory;

    protected $table = 'shift_types';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'shift_code',
        'shift_name',
        'start_time',
        'end_time',
        'break_minutes',
        'is_overnight',
    ];

    protected $casts = [
        'break_minutes' => 'integer',
        'is_overnight' => 'boolean',
        'created_at' => 'datetime',
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

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class, 'shift_type_id');
    }
}
