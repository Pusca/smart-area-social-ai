<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrendSignal extends Model
{
    protected $fillable = [
        'trend_snapshot_id',
        'tenant_id',
        'platform',
        'topic',
        'format_type',
        'hook_patterns',
        'style_notes',
        'freshness_score',
        'saturation_score',
        'estimated_relevance_score',
        'brand_fit_score',
        'novelty_score',
        'execution_feasibility_score',
        'viral_potential_score',
        'risk_flags',
        'source_type',
        'observed_at',
        'meta',
    ];

    protected $casts = [
        'hook_patterns' => 'array',
        'style_notes' => 'array',
        'risk_flags' => 'array',
        'meta' => 'array',
        'freshness_score' => 'decimal:4',
        'saturation_score' => 'decimal:4',
        'estimated_relevance_score' => 'decimal:4',
        'brand_fit_score' => 'decimal:4',
        'novelty_score' => 'decimal:4',
        'execution_feasibility_score' => 'decimal:4',
        'viral_potential_score' => 'decimal:4',
        'observed_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TrendSnapshot::class, 'trend_snapshot_id');
    }
}