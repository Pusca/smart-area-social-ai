<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrendIngestionRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'result_summary' => 'array',
        'meta' => 'array',
        'freshness_score' => 'decimal:4',
        'confidence_score' => 'decimal:4',
        'signals_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
