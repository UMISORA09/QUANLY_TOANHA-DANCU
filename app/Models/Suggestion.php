<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suggestion extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_REVIEWING = 'REVIEWING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const TOPIC_AN_NINH = 'AN_NINH';

    public const TOPIC_VE_SINH = 'VE_SINH';

    public const TOPIC_BAI_XE = 'BAI_XE';

    public const TOPIC_TIEN_ICH = 'TIEN_ICH';

    public const TOPIC_THAI_DO_NHAN_VIEN = 'THAI_DO_NHAN_VIEN';

    public const TOPIC_KHAC = 'KHAC';

    public const SENTIMENT_NOT_ANALYZED = 'NOT_ANALYZED';

    public const SENTIMENT_POSITIVE = 'POSITIVE';

    public const SENTIMENT_NEUTRAL = 'NEUTRAL';

    public const SENTIMENT_NEGATIVE = 'NEGATIVE';

    public const SENTIMENT_VERY_ANGRY = 'VERY_ANGRY';

    protected $table = 'suggestions';

    protected $fillable = [
        'id',
        'box_id',
        'idempotency_key',
        'resident_user_id',
        'apartment_id',
        'is_anonymous',
        'anonymous_access_token_hash',
        'topic_category',
        'title',
        'content_text',
        'attached_files',
        'status',
        'version',
        'official_response_text',
        'rejection_reason',
        'responded_by_staff_id',
        'responded_at',
        'ai_sentiment',
        'ai_sentiment_score',
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'attached_files' => 'array',
            'version' => 'integer',
            'ai_sentiment_score' => 'float',
            'responded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(SuggestionBox::class, 'box_id');
    }

    public function residentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_user_id');
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    public function respondedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_staff_id');
    }
}
