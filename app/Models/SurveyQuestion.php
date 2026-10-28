<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyQuestion extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_RATING_1_TO_5 = 'RATING_1_TO_5';

    public const TYPE_YES_NO = 'YES_NO';

    public const TYPE_MULTIPLE_CHOICE = 'MULTIPLE_CHOICE';

    public const TYPE_FREE_TEXT = 'FREE_TEXT';

    protected $table = 'survey_questions';

    protected $fillable = [
        'id',
        'survey_id',
        'question_order',
        'question_text',
        'question_type',
        'options_json',
        'is_required',
    ];

    protected $casts = [
        'question_order' => 'integer',
        'options_json' => 'array',
        'is_required' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(SatisfactionSurvey::class, 'survey_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(SurveyResponseDetail::class, 'question_id');
    }
}
