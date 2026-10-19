<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'tickets';

    protected $fillable = [
        'ticket_number',
        'idempotency_key',
        'category_id',
        'apartment_id',
        'creator_user_id',
        'title',
        'description',
        'location',
        'priority',
        'status',
        'current_technician_user_id',
        'coordinator_user_id',
        'preferred_service_time',
        'sla_deadline',
        'resolved_at',
        'closed_at',
        'version',
        'is_billable',
        'estimated_repair_cost',
        'actual_repair_cost',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'preferred_service_time' => 'datetime',
            'sla_deadline' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
            'is_billable' => 'boolean',
            'estimated_repair_cost' => 'decimal:2',
            'actual_repair_cost' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_technician_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TicketHistory::class, 'ticket_id')->orderBy('created_at', 'asc');
    }
}
