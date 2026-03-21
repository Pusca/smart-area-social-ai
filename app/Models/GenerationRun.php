<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GenerationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_provider_matrix' => 'array',
        'resolved_provider_matrix' => 'array',
        'requested_output' => 'array',
        'effective_output' => 'array',
        'version_meta' => 'array',
        'result_summary' => 'array',
        'quality_scorecard' => 'array',
        'token_usage' => 'array',
        'estimated_cost_usd' => 'decimal:4',
        'actual_cost_usd' => 'decimal:4',
        'fallback_used' => 'boolean',
        'downgrade_used' => 'boolean',
        'segment_count' => 'integer',
        'retry_count' => 'integer',
        'runtime_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(GenerationAttempt::class);
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(GenerationAttempt::class)->latestOfMany('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineArray(): array
    {
        return [
            'run' => [
                'id' => (int) $this->id,
                'run_key' => (string) $this->run_key,
                'status' => (string) $this->status,
                'scope' => (string) $this->scope,
                'trigger_source' => (string) $this->trigger_source,
                'format' => $this->format,
                'platform' => $this->platform,
                'attempt_count' => (int) $this->attempt_count,
                'runtime_ms' => $this->runtime_ms !== null ? (int) $this->runtime_ms : null,
                'started_at' => optional($this->started_at)->toDateTimeString(),
                'finished_at' => optional($this->finished_at)->toDateTimeString(),
                'completed_at' => optional($this->completed_at)->toDateTimeString(),
                'failed_at' => optional($this->failed_at)->toDateTimeString(),
                'requested_provider_matrix' => $this->requested_provider_matrix,
                'resolved_provider_matrix' => $this->resolved_provider_matrix,
                'requested_output' => $this->requested_output,
                'effective_output' => $this->effective_output,
                'version_meta' => $this->version_meta,
                'versions' => $this->version_meta,
                'quality_scorecard' => $this->quality_scorecard,
                'estimated_cost_usd' => $this->estimated_cost_usd !== null ? (float) $this->estimated_cost_usd : null,
                'actual_cost_usd' => $this->actual_cost_usd !== null ? (float) $this->actual_cost_usd : null,
                'token_usage' => $this->token_usage,
                'fallback_used' => (bool) $this->fallback_used,
                'downgrade_used' => (bool) $this->downgrade_used,
                'segment_count' => (int) ($this->segment_count ?? 0),
                'retry_count' => (int) ($this->retry_count ?? 0),
                'final_provider' => $this->final_provider,
                'failure_mode' => $this->failure_mode,
                'result_summary' => $this->result_summary,
                'last_error' => $this->last_error,
            ],
            'attempts' => $this->relationLoaded('attempts')
                ? $this->attempts
                    ->sortBy('sequence')
                    ->values()
                    ->map(fn (GenerationAttempt $attempt) => $attempt->toTimelineArray())
                    ->all()
                : [],
        ];
    }
}
