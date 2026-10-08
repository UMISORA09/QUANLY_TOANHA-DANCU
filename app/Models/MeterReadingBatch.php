<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeterReadingBatch extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'meter_reading_batches';

    public const UPDATED_AT = null;

    protected $fillable = [
        'batch_code',
        'billing_month_year',
        'meter_type',
        'block_id',
        'file_name',
        'file_url',
        'uploaded_by',
        'total_records',
        'success_records',
        'failed_records',
        'import_status',
        'error_summary_json',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_records' => 'integer',
            'success_records' => 'integer',
            'failed_records' => 'integer',
            'error_summary_json' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class, 'batch_id');
    }
}
