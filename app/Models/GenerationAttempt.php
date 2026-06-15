<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GenerationAttempt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'provider_locked' => 'boolean',
        'tenant_id' => 'integer',
        'requested_duration_seconds' => 'integer',
        'normalized_duration_seconds' => 'integer',
        'retry_index' => 'integer',
        'runtime_ms' => 'integer',
        'duration_ms' => 'integer',
        'input_summary' => 'array',
        'output_summary' => 'array',
        'output_references' => 'array',
        'estimated_cost_usd' => 'decimal:4',
        'actual_cost_usd' => 'decimal:4',
        'token_usage' => 'array',
        'fallback_used' => 'boolean',
        'downgrade_used' => 'boolean',
        'segment_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function parentAttempt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_attempt_id');
    }

    public function childAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_attempt_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineArray(): array
    {
        return [
            'id' => (int) $this->id,
            'parent_attempt_id' => $this->parent_attempt_id !== null ? (int) $this->parent_attempt_id : null,
            'sequence' => (int) $this->sequence,
            'type' => $this->type,
            'stage' => $this->stage,
            'step' => (string) $this->step,
            'status' => (string) $this->status,
            'provider_requested' => $this->provider_requested,
            'provider_effective' => $this->provider_effective,
            'model_requested' => $this->model_requested,
            'model_effective' => $this->model_effective,
            'provider_locked' => (bool) $this->provider_locked,
            'retry_index' => (int) ($this->retry_index ?? 0),
            'requested_duration_seconds' => $this->requested_duration_seconds !== null ? (int) $this->requested_duration_seconds : null,
            'normalized_duration_seconds' => $this->normalized_duration_seconds !== null ? (int) $this->normalized_duration_seconds : null,
            'runtime_ms' => $this->runtime_ms !== null ? (int) $this->runtime_ms : ($this->duration_ms !== null ? (int) $this->duration_ms : null),
            'estimated_cost_usd' => $this->estimated_cost_usd !== null ? (float) $this->estimated_cost_usd : null,
            'actual_cost_usd' => $this->actual_cost_usd !== null ? (float) $this->actual_cost_usd : null,
            'token_usage' => $this->token_usage,
            'fallback_used' => (bool) $this->fallback_used,
            'downgrade_used' => (bool) $this->downgrade_used,
            'segment_count' => (int) ($this->segment_count ?? 0),
            'final_provider' => $this->final_provider,
            'failure_mode' => $this->failure_mode,
            'external_request_id' => $this->external_request_id,
            'external_response_id' => $this->external_response_id,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'input_hash' => $this->input_hash,
            'input_summary' => $this->input_summary,
            'output_summary' => $this->output_summary,
            'output_references' => $this->output_references,
            'started_at' => optional($this->started_at)->toDateTimeString(),
            'finished_at' => optional($this->finished_at)->toDateTimeString(),
            'completed_at' => optional($this->completed_at)->toDateTimeString(),
            'failed_at' => optional($this->failed_at)->toDateTimeString(),
        ];
    }
}
