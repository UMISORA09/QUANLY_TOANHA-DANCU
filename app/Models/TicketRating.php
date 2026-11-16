<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketRating extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ticket_ratings';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'ticket_id',
        'resident_user_id',
        'rating_stars',
        'feedback_tags',
        'review_comment',
        'created_at',
    ];

    protected $casts = [
        'rating_stars' => 'integer',
        'feedback_tags' => 'array',
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_user_id');
    }
}
