<?php

namespace App\Modules\ResidentService\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model Bảng tin / Thông báo.
 */
class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'category',
        'priority',
        'author_id',
        'published_at',
        'expires_at',
        'is_pinned',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_pinned' => 'boolean',
    ];

    /**
     * Scope: Chỉ lấy thông báo đang hiển thị.
     */
    public function scopeActive($query)
    {
        return $query->where('published_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            });
    }
}
