<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SuggestionBox extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'suggestion_boxes';

    protected $fillable = [
        'id',
        'box_name',
        'block_id',
        'description',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class, 'box_id');
    }
}
