<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SatisfactionSurvey extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_UPCOMING = 'UPCOMING';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_DISABLED = 'DISABLED';

    protected $table = 'satisfaction_surveys';

    protected $fillable = [
        'id',
        'survey_code',
        'title',
        'description',
        'target_block_id',
        'start_date',
        'end_date',
        'is_active',
        'created_by',
        'version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function targetBlock(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'target_block_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class, 'survey_id')->orderBy('question_order', 'asc');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class, 'survey_id');
    }

    /**
     * Determine computed status based on active flag and schedule in Asia/Ho_Chi_Minh.
     */
    public function getComputedStatusAttribute(): string
    {
        if (! $this->is_active) {
            return self::STATUS_DISABLED;
        }

        $now = Carbon::now('Asia/Ho_Chi_Minh')->startOfDay();
        $start = Carbon::parse($this->start_date, 'Asia/Ho_Chi_Minh')->startOfDay();
        $end = Carbon::parse($this->end_date, 'Asia/Ho_Chi_Minh')->endOfDay();

        if ($now->lt($start)) {
            return self::STATUS_UPCOMING;
        }

        if ($now->gt($end)) {
            return self::STATUS_CLOSED;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Check if survey is currently accepting submissions.
     */
    public function isOpen(): bool
    {
        return $this->computed_status === self::STATUS_ACTIVE;
    }
}
