<?php

namespace App\Modules\ResidentService\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Model Ticket — Báo cáo sự cố / Yêu cầu hỗ trợ.
 * Sử dụng State Machine: open → in_progress → resolved → closed.
 */
class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'resident_id',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'assigned_to',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    // State Machine statuses
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    // Priority levels
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    /**
     * Trạng thái tiếp theo hợp lệ (State Machine).
     */
    public static array $allowedTransitions = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_CLOSED],
        self::STATUS_IN_PROGRESS => [self::STATUS_RESOLVED, self::STATUS_OPEN],
        self::STATUS_RESOLVED => [self::STATUS_CLOSED, self::STATUS_IN_PROGRESS],
        self::STATUS_CLOSED => [],
    ];

    /**
     * Kiểm tra có thể chuyển sang trạng thái mới không.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::$allowedTransitions[$this->status] ?? []);
    }

    /**
     * File đính kèm (polymorphic).
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
