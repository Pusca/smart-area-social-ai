<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\GenerationAttempt;
use App\Models\GenerationRun;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GenerationAuditService
{
    private ?bool $tablesAvailable = null;

    public function startRun(ContentItem $item, string $runKey, array $attributes = []): ?GenerationRun
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $payload = array_merge([
            'tenant_id' => (int) $item->tenant_id,
            'content_item_id' => (int) $item->id,
            'content_plan_id' => $item->content_plan_id ? (int) $item->content_plan_id : null,
            'run_key' => $runKey,
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'running',
            'format' => (string) $item->format,
            'platform' => (string) $item->platform,
            'started_at' => now(),
        ], $this->sanitizeRunAttributes($attributes));

        $run = GenerationRun::query()->updateOrCreate(
            ['run_key' => $runKey],
            $payload
        );

        return $run->fresh() ?? $run;
    }

    public function syncRun(?GenerationRun $run, array $attributes = []): ?GenerationRun
    {
        if (!$this->isEnabled() || !$run) {
            return $run;
        }

        $payload = $this->sanitizeRunAttributes($attributes);
        if ($run->started_at && isset($payload['finished_at']) && !isset($payload['runtime_ms'])) {
            $payload['runtime_ms'] = max(0, $payload['finished_at']->diffInMilliseconds($run->started_at));
        }

        $run->fill($payload);
        $run->save();

        return $run->fresh() ?? $run;
    }

    public function completeRun(?GenerationRun $run, array $attributes = []): ?GenerationRun
    {
        if (!$this->isEnabled() || !$run) {
            return $run;
        }

        $payload = $this->sanitizeRunAttributes($attributes);
        $status = trim((string) ($payload['status'] ?? 'succeeded')) ?: 'succeeded';
        $payload['status'] = $status;
        $payload['finished_at'] = $payload['finished_at'] ?? now();

        if ($run->started_at && !isset($payload['runtime_ms'])) {
            $payload['runtime_ms'] = max(0, $payload['finished_at']->diffInMilliseconds($run->started_at));
        }

        if ($status === 'failed') {
            $payload['failed_at'] = $payload['failed_at'] ?? $payload['finished_at'];
        } else {
            $payload['completed_at'] = $payload['completed_at'] ?? $payload['finished_at'];
        }

        return $this->syncRun($run, $payload);
    }

    public function failRun(?GenerationRun $run, Throwable|string $error, array $attributes = []): ?GenerationRun
    {
        if (!$this->isEnabled() || !$run) {
            return $run;
        }

        $payload = $this->sanitizeRunAttributes($attributes);
        $payload['status'] = 'failed';
        $payload['last_error'] = $payload['last_error'] ?? $this->errorMessage($error);

        return $this->completeRun($run, $payload);
    }

    public function failRunByKey(int $contentItemId, string $runKey, Throwable|string $error, array $attributes = []): ?GenerationRun
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $run = GenerationRun::query()
            ->where('content_item_id', $contentItemId)
            ->where('run_key', $runKey)
            ->first();

        if (!$run) {
            $run = GenerationRun::query()
                ->where('content_item_id', $contentItemId)
                ->whereIn('status', ['running', 'pending'])
                ->latest('id')
                ->first();
        }

        return $this->failRun($run, $error, $attributes);
    }

    public function startAttempt(?GenerationRun $run, string $step, array $attributes = []): ?GenerationAttempt
    {
        if (!$this->isEnabled() || !$run) {
            return null;
        }

        $run->refresh();
        $sequence = max(1, ((int) $run->attempt_count) + 1);
        $payload = array_merge([
            'generation_run_id' => (int) $run->id,
            'tenant_id' => (int) $run->tenant_id,
            'content_item_id' => (int) $run->content_item_id,
            'parent_attempt_id' => null,
            'sequence' => $sequence,
            'stage' => $step,
            'step' => $step,
            'type' => $this->inferAttemptType($step),
            'status' => 'running',
            'retry_index' => 0,
            'started_at' => now(),
        ], $this->sanitizeAttemptAttributes($attributes));

        if (empty($payload['input_hash']) && !empty($payload['input_summary'])) {
            $payload['input_hash'] = $this->buildInputHash($payload['input_summary']);
        }

        $attempt = GenerationAttempt::query()->create($payload);

        $run->forceFill([
            'attempt_count' => $sequence,
        ])->save();

        return $attempt->fresh() ?? $attempt;
    }

    public function completeAttempt(?GenerationAttempt $attempt, array $attributes = []): ?GenerationAttempt
    {
        if (!$this->isEnabled() || !$attempt) {
            return $attempt;
        }

        $payload = $this->sanitizeAttemptAttributes($attributes);
        $status = trim((string) ($payload['status'] ?? 'succeeded')) ?: 'succeeded';
        $payload['status'] = $status;
        $payload['finished_at'] = $payload['finished_at'] ?? now();

        if ($attempt->started_at) {
            $runtime = max(0, $payload['finished_at']->diffInMilliseconds($attempt->started_at));
            $payload['runtime_ms'] = $payload['runtime_ms'] ?? $runtime;
            $payload['duration_ms'] = $payload['duration_ms'] ?? $payload['runtime_ms'];
        }

        if ($status === 'failed') {
            $payload['failed_at'] = $payload['failed_at'] ?? $payload['finished_at'];
        } else {
            $payload['completed_at'] = $payload['completed_at'] ?? $payload['finished_at'];
        }

        if (empty($payload['input_hash'])) {
            $inputSummary = $payload['input_summary'] ?? $attempt->input_summary;
            if (!empty($inputSummary)) {
                $payload['input_hash'] = $this->buildInputHash($inputSummary);
            }
        }

        $attempt->fill($payload);
        $attempt->save();

        return $attempt->fresh() ?? $attempt;
    }

    public function failAttempt(?GenerationAttempt $attempt, Throwable|string $error, array $attributes = []): ?GenerationAttempt
    {
        if (!$this->isEnabled() || !$attempt) {
            return $attempt;
        }

        $payload = $this->sanitizeAttemptAttributes($attributes);
        $payload['status'] = 'failed';
        $payload['error_message'] = $payload['error_message'] ?? $this->errorMessage($error);
        $payload['error_code'] = $payload['error_code'] ?? $this->errorCode($error);

        return $this->completeAttempt($attempt, $payload);
    }

    /**
     * @param  GenerationRun|int|string|null  $run
     * @return array<string, mixed>|null
     */
    public function timelineForRun(GenerationRun|int|string|null $run): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $resolved = $this->resolveRun($run);
        if (!$resolved) {
            return null;
        }

        $resolved->loadMissing([
            'attempts' => fn ($query) => $query->orderBy('sequence')->orderBy('id'),
        ]);

        return $resolved->toTimelineArray();
    }

    /**
     * @param  ContentItem|int  $contentItem
     * @return array<string, mixed>|null
     */
    public function timelineForContentItem(ContentItem|int $contentItem, ?string $runKey = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $contentItemId = $contentItem instanceof ContentItem
            ? (int) $contentItem->id
            : (int) $contentItem;

        $query = GenerationRun::query()
            ->where('content_item_id', $contentItemId);

        if ($runKey !== null && trim($runKey) !== '') {
            $query->where('run_key', trim($runKey));
        } else {
            $query->latest('id');
        }

        $run = $query->first();

        return $this->timelineForRun($run);
    }

    private function isEnabled(): bool
    {
        if ($this->tablesAvailable === null) {
            $this->tablesAvailable = Schema::hasTable('generation_runs') && Schema::hasTable('generation_attempts');
        }

        return $this->tablesAvailable;
    }

    private function resolveRun(GenerationRun|int|string|null $run): ?GenerationRun
    {
        if ($run instanceof GenerationRun) {
            return $run;
        }

        if (is_int($run) || ctype_digit((string) $run)) {
            return GenerationRun::query()->find((int) $run);
        }

        if (is_string($run) && trim($run) !== '') {
            return GenerationRun::query()->where('run_key', trim($run))->first();
        }

        return null;
    }

    private function sanitizeRunAttributes(array $attributes): array
    {
        $allowed = [
            'scope',
            'trigger_source',
            'status',
            'format',
            'platform',
            'requested_provider_matrix',
            'resolved_provider_matrix',
            'requested_output',
            'effective_output',
            'version_meta',
            'result_summary',
            'quality_scorecard',
            'overlay_meta',
            'storyboard_meta',
            'attempt_count',
            'retry_count',
            'estimated_cost_usd',
            'actual_cost_usd',
            'token_usage',
            'fallback_used',
            'downgrade_used',
            'segment_count',
            'final_provider',
            'failure_mode',
            'runtime_ms',
            'last_error',
            'started_at',
            'finished_at',
            'completed_at',
            'failed_at',
        ];

        return array_intersect_key($attributes, array_flip($allowed));
    }

    private function sanitizeAttemptAttributes(array $attributes): array
    {
        $allowed = [
            'parent_attempt_id',
            'sequence',
            'tenant_id',
            'type',
            'stage',
            'step',
            'status',
            'provider_requested',
            'provider_effective',
            'model_requested',
            'model_effective',
            'provider_locked',
            'request_mode',
            'input_summary',
            'input_hash',
            'output_summary',
            'output_references',
            'requested_duration_seconds',
            'normalized_duration_seconds',
            'retry_index',
            'external_request_id',
            'external_response_id',
            'error_code',
            'error_message',
            'estimated_cost_usd',
            'actual_cost_usd',
            'token_usage',
            'fallback_used',
            'downgrade_used',
            'segment_count',
            'final_provider',
            'failure_mode',
            'runtime_ms',
            'duration_ms',
            'started_at',
            'finished_at',
            'completed_at',
            'failed_at',
        ];

        $payload = array_intersect_key($attributes, array_flip($allowed));

        if (!isset($payload['step']) && isset($payload['stage'])) {
            $payload['step'] = (string) $payload['stage'];
        }

        if (!isset($payload['stage']) && isset($payload['step'])) {
            $payload['stage'] = (string) $payload['step'];
        }

        if (!isset($payload['type']) && isset($payload['stage'])) {
            $payload['type'] = $this->inferAttemptType((string) $payload['stage']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $inputSummary
     */
    private function buildInputHash(array $inputSummary): string
    {
        return hash('sha256', json_encode($inputSummary, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function inferAttemptType(string $stage): string
    {
        $stage = strtolower(trim($stage));

        return match (true) {
            str_contains($stage, 'speech'), str_contains($stage, 'voice') => 'speech',
            str_contains($stage, 'video'), str_contains($stage, 'mux') => 'video',
            str_contains($stage, 'image'), str_contains($stage, 'visual') => 'image',
            str_contains($stage, 'align') => 'alignment',
            default => 'text',
        };
    }

    private function errorMessage(Throwable|string $error): string
    {
        if ($error instanceof Throwable) {
            return trim($error->getMessage());
        }

        return trim((string) $error);
    }

    private function errorCode(Throwable|string $error): string
    {
        if ($error instanceof Throwable) {
            $short = class_basename($error);

            return trim((string) $short);
        }

        return 'runtime_error';
    }
}
