<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreshnessIncident extends Model
{
    protected $table = 'freshness_incidents';

    protected $fillable = [
        'source',
        'source_type',
        'state',
        'age_seconds',
        'threshold_seconds',
        'source_timestamp',
        'detected_at',
        'resolved_at',
        'details',
    ];

    protected $casts = [
        'age_seconds' => 'integer',
        'threshold_seconds' => 'integer',
        'source_timestamp' => 'datetime',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
