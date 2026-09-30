<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreshnessHeartbeat extends Model
{
    protected $table = 'freshness_heartbeats';

    protected $primaryKey = 'channel';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'channel',
        'last_success_at',
        'status',
        'details',
        'metadata',
    ];

    protected $casts = [
        'last_success_at' => 'datetime',
        'metadata' => 'array',
    ];
}
