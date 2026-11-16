<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketCategory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ticket_categories';

    public $timestamps = false;

    protected $fillable = [
        'category_code',
        'category_name',
        'default_priority',
        'sla_response_time_minutes',
        'sla_resolution_time_hours',
        'default_assigned_team',
    ];

    protected function casts(): array
    {
        return [
            'sla_response_time_minutes' => 'integer',
            'sla_resolution_time_hours' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }
}
