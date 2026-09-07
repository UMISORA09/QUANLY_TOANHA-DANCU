<?php

namespace App\Modules\ResidentService\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Model File đính kèm (Polymorphic).
 * Có thể attach vào Ticket, Announcement, hoặc bất kỳ model nào.
 */
class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Model gốc (Ticket, Announcement, ...).
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
