<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyResponse extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'survey_responses';

    protected $fillable = [
        'id',
        'survey_id',
        'resident_user_id',
        'apartment_id',
        'idempotency_key',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(SatisfactionSurvey::class, 'survey_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_user_id');
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(SurveyResponseDetail::class, 'response_id');
    }
}
