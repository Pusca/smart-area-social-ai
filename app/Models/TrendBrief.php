<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrendBrief extends Model
{
    protected $fillable = [
        'tenant_id',
        'brief_key',
        'source_snapshot_id',
        'snapshot',
        'summary',
        'freshness_score',
        'confidence_score',
        'expires_at',
        'fetched_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'summary' => 'array',
        'freshness_score' => 'decimal:4',
        'confidence_score' => 'decimal:4',
        'expires_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];
}
