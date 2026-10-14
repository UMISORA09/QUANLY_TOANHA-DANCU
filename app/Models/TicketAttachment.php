<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ticket_attachments';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'file_name',
        'file_url',
        'storage_path',
        'file_type',
        'mime_type',
        'file_size_bytes',
        'is_resolution_proof',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'is_resolution_proof' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
