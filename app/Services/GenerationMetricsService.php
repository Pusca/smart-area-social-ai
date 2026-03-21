<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\GenerationAttempt;
use App\Models\GenerationRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationMetricsService
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildTextAttemptMetrics(ContentItem $item, array $context = []): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $provider = $this->normalizeProvider((string) ($context['provider_effective'] ?? data_get($meta, 'text_provider_last_used', data_get($meta, 'provider_matrix.text.provider', 'openai'))));
        $model = trim((string) ($context['model_effective'] ?? config('openai.text_model', 'gpt-4.1-mini')));
        $tokenUsage = $this->normalizeTokenUsage($context['token_usage'] ?? []);
        $retryIndex = max(0, (int) ($context['retry_index'] ?? 0));
        $fallbackUsed = (bool) ($context['fallback_used'] ?? data_get($meta, 'text_fallback', false));
        $failureMode = trim((string) ($context['failure_mode'] ?? ''));

        if ($failureMode === '' && (($context['status'] ?? null) === 'failed' || ($context['status'] ?? null) === 'degraded')) {
            $failureMode = $this->classifyFailureMode((string) ($context['error_message'] ?? $item->ai_error));
        }

        return [
            'tenant_id' => (int) $item->tenant_id,
            'estimated_cost_usd' => $this->estimateTextCost($provider, $model, $tokenUsage),
            'actual_cost_usd' => $this->normalizeCurrency($context['actual_cost_usd'] ?? null),
            'token_usage' => $tokenUsage ?: null,
            'fallback_used' => $fallbackUsed,
            'downgrade_used' => (bool) ($context['downgrade_used'] ?? false),
            'segment_count' => 0,
            'final_provider' => $provider !== '' ? $provider : null,
            'failure_mode' => $failureMode !== '' ? $failureMode : null,
            'retry_index' => $retryIndex,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildVisualAttemptMetrics(ContentItem $item, array $context = []): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $isVideo = in_array(Str::lower(trim((string) $item->format)), ['reel', 'story', 'video'], true);
        $provider = $this->normalizeProvider((string) ($context['provider_effective'] ?? $this->resolveVisualProvider($meta, $isVideo)));
        $model = trim((string) ($context['model_effective'] ?? $this->resolveVisualModel($meta, $provider, $isVideo)));
        $segmentCount = max(0, (int) ($context['segment_count'] ?? ($isVideo ? data_get($meta, 'video_generation.segment_count', 0) : 0)));
        $retryIndex = max(0, (int) ($context['retry_index'] ?? $this->deriveVisualRetryIndex($meta, $isVideo)));
        $fallbackUsed = (bool) ($context['fallback_used'] ?? $this->visualFallbackUsed($meta, $isVideo));
        $downgradeUsed = (bool) ($context['downgrade_used'] ?? $this->visualDowngradeUsed($meta, $isVideo));
        $failureMode = trim((string) ($context['failure_mode'] ?? ''));

        if ($failureMode === '' && (($context['status'] ?? null) === 'failed' || ($context['status'] ?? null) === 'degraded')) {
            $failureMode = $this->classifyFailureMode((string) ($context['error_message'] ?? $item->ai_error));
        }

        return [
            'tenant_id' => (int) $item->tenant_id,
            'estimated_cost_usd' => $isVideo
                ? $this->estimateVideoCost($provider, $model, $meta)
                : $this->estimateImageCost($provider, $model, $meta),
            'actual_cost_usd' => $this->normalizeCurrency($context['actual_cost_usd'] ?? null),
            'token_usage' => $this->normalizeTokenUsage($context['token_usage'] ?? []),
            'fallback_used' => $fallbackUsed,
            'downgrade_used' => $downgradeUsed,
            'segment_count' => $segmentCount,
            'final_provider' => $provider !== '' ? $provider : null,
            'failure_mode' => $failureMode !== '' ? $failureMode : null,
            'retry_index' => $retryIndex,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRunMetrics(ContentItem $item, ?GenerationRun $run = null): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $attempts = $run
            ? $run->attempts()->orderBy('sequence')->get()
            : collect();

        $estimatedCost = 0.0;
        $actualCost = 0.0;
        $hasActualCost = false;
        $retryCount = 0;
        $fallbackUsed = false;
        $downgradeUsed = false;
        $segmentCount = 0;
        $tokenUsage = [];
        $failureMode = '';

        foreach ($attempts as $attempt) {
            $estimatedCost += (float) ($attempt->estimated_cost_usd ?? 0);
            if ($attempt->actual_cost_usd !== null) {
                $actualCost += (float) $attempt->actual_cost_usd;
                $hasActualCost = true;
            }
            $retryCount += (int) ($attempt->retry_index ?? 0);
            $fallbackUsed = $fallbackUsed || (bool) ($attempt->fallback_used ?? false);
            $downgradeUsed = $downgradeUsed || (bool) ($attempt->downgrade_used ?? false);
            $segmentCount = max($segmentCount, (int) ($attempt->segment_count ?? 0));
            $tokenUsage = $this->mergeTokenUsage($tokenUsage, is_array($attempt->token_usage) ? $attempt->token_usage : []);
            if ($failureMode === '' && trim((string) ($attempt->failure_mode ?? '')) !== '') {
                $failureMode = trim((string) $attempt->failure_mode);
            }
        }

        $audioEstimate = $this->estimateAudioCostFromItem($item);
        $estimatedCost += $audioEstimate;

        if (!$fallbackUsed) {
            $fallbackUsed = $this->runFallbackUsedFromMeta($meta);
        }
        if (!$downgradeUsed) {
            $downgradeUsed = $this->runDowngradeUsedFromMeta($meta);
        }
        if ($segmentCount === 0) {
            $segmentCount = (int) data_get($meta, 'video_generation.segment_count', 0);
        }
        if ($failureMode === '') {
            $failureMode = $this->classifyFailureMode((string) ($item->ai_error ?? ''));
        }

        return [
            'tenant_id' => (int) $item->tenant_id,
            'estimated_cost_usd' => $this->normalizeCurrency($estimatedCost),
            'actual_cost_usd' => $hasActualCost ? $this->normalizeCurrency($actualCost) : null,
            'token_usage' => !empty($tokenUsage) ? $tokenUsage : null,
            'fallback_used' => $fallbackUsed,
            'downgrade_used' => $downgradeUsed,
            'segment_count' => max(0, $segmentCount),
            'retry_count' => max(0, $retryCount),
            'final_provider' => $this->resolveRunFinalProvider($item),
            'failure_mode' => $failureMode !== '' ? $failureMode : null,
        ];
    }

    public function costByTenant(?int $tenantId = null, ?int $days = null): Collection
    {
        $query = $this->runsQuery($tenantId, $days)
            ->selectRaw('tenant_id')
            ->selectRaw('COUNT(*) as runs_count')
            ->selectRaw('SUM(COALESCE(estimated_cost_usd, 0)) as estimated_cost_usd')
            ->selectRaw('SUM(COALESCE(actual_cost_usd, 0)) as actual_cost_usd')
            ->selectRaw('SUM(COALESCE(actual_cost_usd, estimated_cost_usd, 0)) as effective_cost_usd')
            ->groupBy('tenant_id')
            ->orderByDesc('effective_cost_usd')
            ->get();

        $tenantNames = DB::table('tenants')
            ->whereIn('id', $query->pluck('tenant_id')->all())
            ->pluck('name', 'id');

        return $query->map(fn ($row) => [
            'tenant_id' => (int) $row->tenant_id,
            'tenant_name' => (string) ($tenantNames[(int) $row->tenant_id] ?? ('Tenant #' . (int) $row->tenant_id)),
            'runs_count' => (int) $row->runs_count,
            'estimated_cost_usd' => (float) $row->estimated_cost_usd,
            'actual_cost_usd' => (float) $row->actual_cost_usd,
            'effective_cost_usd' => (float) $row->effective_cost_usd,
        ]);
    }

    public function costByProvider(?int $tenantId = null, ?int $days = null): Collection
    {
        return $this->attemptsQuery($tenantId, $days)
            ->selectRaw("COALESCE(final_provider, provider_effective, provider_requested, 'unknown') as provider")
            ->selectRaw('COUNT(*) as attempts_count')
            ->selectRaw('SUM(COALESCE(estimated_cost_usd, 0)) as estimated_cost_usd')
            ->selectRaw('SUM(COALESCE(actual_cost_usd, 0)) as actual_cost_usd')
            ->selectRaw('SUM(COALESCE(actual_cost_usd, estimated_cost_usd, 0)) as effective_cost_usd')
            ->groupBy('provider')
            ->orderByDesc('effective_cost_usd')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'attempts_count' => (int) $row->attempts_count,
                'estimated_cost_usd' => (float) $row->estimated_cost_usd,
                'actual_cost_usd' => (float) $row->actual_cost_usd,
                'effective_cost_usd' => (float) $row->effective_cost_usd,
            ]);
    }

    public function averageLatencyByProvider(?int $tenantId = null, ?int $days = null): Collection
    {
        return $this->attemptsQuery($tenantId, $days)
            ->whereNotNull('runtime_ms')
            ->selectRaw("COALESCE(final_provider, provider_effective, provider_requested, 'unknown') as provider")
            ->selectRaw('COUNT(*) as attempts_count')
            ->selectRaw('AVG(runtime_ms) as avg_runtime_ms')
            ->groupBy('provider')
            ->orderBy('provider')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'attempts_count' => (int) $row->attempts_count,
                'avg_runtime_ms' => (int) round((float) $row->avg_runtime_ms),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function failureRate(?int $tenantId = null, ?int $days = null): array
    {
        $total = $this->runsQuery($tenantId, $days)->count();
        $failed = $this->runsQuery($tenantId, $days)->where('status', 'failed')->count();

        return $this->ratePayload($failed, $total, 'failed_runs', 'total_runs');
    }

    /**
     * @return array<string, mixed>
     */
    public function retryRate(?int $tenantId = null, ?int $days = null): array
    {
        $total = $this->runsQuery($tenantId, $days)->count();
        $retried = $this->runsQuery($tenantId, $days)->where('retry_count', '>', 0)->count();

        return $this->ratePayload($retried, $total, 'retried_runs', 'total_runs');
    }

    /**
     * @return array<string, mixed>
     */
    public function downgradeRate(?int $tenantId = null, ?int $days = null): array
    {
        $total = $this->runsQuery($tenantId, $days)->count();
        $downgraded = $this->runsQuery($tenantId, $days)->where('downgrade_used', true)->count();

        return $this->ratePayload($downgraded, $total, 'downgraded_runs', 'total_runs');
    }

    /**
     * @return array<string, mixed>
     */
    public function fallbackRate(?int $tenantId = null, ?int $days = null): array
    {
        $total = $this->runsQuery($tenantId, $days)->count();
        $fallbacks = $this->runsQuery($tenantId, $days)->where('fallback_used', true)->count();

        return $this->ratePayload($fallbacks, $total, 'fallback_runs', 'total_runs');
    }

    public function failureModes(?int $tenantId = null, ?int $days = null): Collection
    {
        return $this->runsQuery($tenantId, $days)
            ->whereNotNull('failure_mode')
            ->where('failure_mode', '!=', '')
            ->selectRaw('failure_mode')
            ->selectRaw('COUNT(*) as runs_count')
            ->groupBy('failure_mode')
            ->orderByDesc('runs_count')
            ->orderBy('failure_mode')
            ->get()
            ->map(fn ($row) => [
                'failure_mode' => (string) $row->failure_mode,
                'runs_count' => (int) $row->runs_count,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardSnapshot(?int $tenantId = null, ?int $days = null): array
    {
        $days = $this->resolveWindowDays($days);
        $costByTenant = $this->costByTenant($tenantId, $days);
        $costByProvider = $this->costByProvider($tenantId, $days);
        $latencyByProvider = $this->averageLatencyByProvider($tenantId, $days);
        $failureRate = $this->failureRate($tenantId, $days);
        $retryRate = $this->retryRate($tenantId, $days);
        $downgradeRate = $this->downgradeRate($tenantId, $days);
        $fallbackRate = $this->fallbackRate($tenantId, $days);
        $failureModes = $this->failureModes($tenantId, $days);

        $runsCount = $this->runsQuery($tenantId, $days)->count();
        $estimatedCost = (float) $this->runsQuery($tenantId, $days)->sum(DB::raw('COALESCE(estimated_cost_usd, 0)'));
        $actualCost = (float) $this->runsQuery($tenantId, $days)->sum(DB::raw('COALESCE(actual_cost_usd, 0)'));
        $effectiveCost = (float) $this->runsQuery($tenantId, $days)->sum(DB::raw('COALESCE(actual_cost_usd, estimated_cost_usd, 0)'));

        return [
            'window_days' => $days,
            'summary' => [
                'runs_count' => $runsCount,
                'estimated_cost_usd' => round($estimatedCost, 4),
                'actual_cost_usd' => round($actualCost, 4),
                'effective_cost_usd' => round($effectiveCost, 4),
                'failure_rate' => $failureRate,
                'retry_rate' => $retryRate,
                'downgrade_rate' => $downgradeRate,
                'fallback_rate' => $fallbackRate,
            ],
            'cost_by_tenant' => $costByTenant->all(),
            'cost_by_provider' => $costByProvider->all(),
            'latency_by_provider' => $latencyByProvider->all(),
            'failure_modes' => $failureModes->all(),
        ];
    }

    private function runsQuery(?int $tenantId = null, ?int $days = null): Builder
    {
        $query = GenerationRun::query();
        $windowDays = $this->resolveWindowDays($days);

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->where('started_at', '>=', now()->subDays($windowDays));
    }

    private function attemptsQuery(?int $tenantId = null, ?int $days = null): Builder
    {
        $query = GenerationAttempt::query();
        $windowDays = $this->resolveWindowDays($days);

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->where('started_at', '>=', now()->subDays($windowDays));
    }

    private function resolveWindowDays(?int $days): int
    {
        $days = $days ?? (int) config('ai_observability.default_window_days', 30);

        return max(1, min(365, $days));
    }

    /**
     * @param  array<string, mixed>|mixed  $usage
     * @return array<string, int>
     */
    private function normalizeTokenUsage(mixed $usage): array
    {
        if (!is_array($usage)) {
            return [];
        }

        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));

        $normalized = array_filter([
            'input_tokens' => $input > 0 ? $input : null,
            'output_tokens' => $output > 0 ? $output : null,
            'total_tokens' => $total > 0 ? $total : null,
        ], fn ($value) => $value !== null);

        return array_map('intval', $normalized);
    }

    /**
     * @param  array<string, int>  $base
     * @param  array<string, int>  $add
     * @return array<string, int>
     */
    private function mergeTokenUsage(array $base, array $add): array
    {
        $keys = array_unique(array_merge(array_keys($base), array_keys($add)));
        $merged = [];

        foreach ($keys as $key) {
            $merged[$key] = (int) ($base[$key] ?? 0) + (int) ($add[$key] ?? 0);
        }

        return array_filter($merged, fn ($value) => $value > 0);
    }

    private function estimateTextCost(string $provider, string $model, array $usage): ?float
    {
        if ($provider !== 'openai' || empty($usage)) {
            return null;
        }

        $rates = (array) config('ai_observability.pricing.text.openai.models.' . $model, []);
        if (empty($rates)) {
            $rates = (array) config('ai_observability.pricing.text.openai.default', []);
        }

        $inputRate = (float) ($rates['input_per_million_tokens_usd'] ?? 0);
        $outputRate = (float) ($rates['output_per_million_tokens_usd'] ?? 0);
        if ($inputRate <= 0 && $outputRate <= 0) {
            return null;
        }

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $cost = ($inputTokens / 1000000) * $inputRate
            + ($outputTokens / 1000000) * $outputRate;

        return $this->normalizeCurrency($cost);
    }

    private function estimateImageCost(string $provider, string $model, array $meta): ?float
    {
        if ($provider === 'nanobanana') {
            $perRequest = config('ai_observability.pricing.image.nanobanana.models.' . $model . '.per_request_usd');
            if ($perRequest === null) {
                $perRequest = config('ai_observability.pricing.image.nanobanana.default_per_request_usd');
            }

            return $this->normalizeCurrency($perRequest);
        }

        if ($provider === 'openai') {
            $size = trim((string) config('openai.image_size', '1024x1024'));
            $perRequest = config('ai_observability.pricing.image.openai.models.' . $model . '.per_request_usd_by_size.' . $size);
            if ($perRequest === null) {
                $perRequest = config('ai_observability.pricing.image.openai.default_per_request_usd');
            }

            return $this->normalizeCurrency($perRequest);
        }

        return null;
    }

    private function estimateVideoCost(string $provider, string $model, array $meta): ?float
    {
        $seconds = $this->videoBillableSeconds($meta);
        if ($seconds <= 0) {
            return null;
        }

        $perSecond = null;
        if ($provider === 'openai') {
            $size = trim((string) data_get($meta, 'video_generation.request_summary.size', ''));
            if ($model === 'sora-2-pro' && in_array($size, ['1024x1792', '1792x1024'], true)) {
                $perSecond = config('ai_observability.pricing.video.openai.models.sora-2-pro.per_second_usd_high_res');
            } else {
                $perSecond = config('ai_observability.pricing.video.openai.models.' . $model . '.per_second_usd');
            }

            if ($perSecond === null) {
                $perSecond = config('ai_observability.pricing.video.openai.default_per_second_usd');
            }
        } elseif ($provider === 'runway') {
            $perSecond = config('ai_observability.pricing.video.runway.models.' . $model . '.per_second_usd');
            if ($perSecond === null) {
                $perSecond = config('ai_observability.pricing.video.runway.default_per_second_usd');
            }
        } elseif ($provider === 'kling') {
            $perSecond = config('ai_observability.pricing.video.kling.models.' . $model . '.per_second_usd');
            if ($perSecond === null) {
                $perSecond = config('ai_observability.pricing.video.kling.default_per_second_usd');
            }
        }

        if ($perSecond === null) {
            return null;
        }

        return $this->normalizeCurrency(((float) $perSecond) * $seconds);
    }

    private function estimateAudioCostFromItem(ContentItem $item): float
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $audioProvider = $this->normalizeProvider((string) data_get($meta, 'video_generation.audio.provider', ''));
        if ($audioProvider === '') {
            return 0.0;
        }

        $text = trim((string) data_get($meta, 'video_voiceover', ''));
        if ($text === '') {
            $text = trim((string) ($item->ai_caption ?? ''));
        }
        if ($text === '') {
            return 0.0;
        }

        $per1kChars = config('ai_observability.pricing.speech.' . $audioProvider . '.default_per_1k_chars_usd');
        if ($per1kChars === null) {
            return 0.0;
        }

        $chars = max(1, mb_strlen($text, 'UTF-8'));

        return (float) $this->normalizeCurrency(($chars / 1000) * (float) $per1kChars);
    }

    private function videoBillableSeconds(array $meta): int
    {
        $delivered = (int) data_get($meta, 'video_generation.request_summary.delivered_seconds', 0);
        if ($delivered > 0) {
            return $delivered;
        }

        $target = (int) data_get($meta, 'video_generation.target_total_seconds', 0);
        if ($target > 0) {
            return $target;
        }

        $normalized = (int) data_get($meta, 'video_generation.request_summary.seconds', 0);
        if ($normalized > 0) {
            return $normalized;
        }

        return 0;
    }

    private function deriveVisualRetryIndex(array $meta, bool $isVideo): int
    {
        if ($isVideo) {
            return max(0, (int) data_get($meta, 'video_generation.generation_attempts', 1) - 1);
        }

        return data_get($meta, 'image_generation.fallback', null) ? 1 : 0;
    }

    private function visualFallbackUsed(array $meta, bool $isVideo): bool
    {
        if ($isVideo) {
            return trim((string) data_get($meta, 'video_generation.provider_fallback', '')) !== ''
                || !empty(data_get($meta, 'video_generation.extended_fallback'))
                || trim((string) data_get($meta, 'video_generation.fallback', '')) !== '';
        }

        return trim((string) data_get($meta, 'image_generation.fallback', data_get($meta, 'image_fallback', ''))) !== '';
    }

    private function visualDowngradeUsed(array $meta, bool $isVideo): bool
    {
        if ($isVideo) {
            return !empty(data_get($meta, 'video_generation.extended_fallback'));
        }

        return trim((string) data_get($meta, 'image_generation.fallback', data_get($meta, 'image_fallback', ''))) === 'local_placeholder';
    }

    private function runFallbackUsedFromMeta(array $meta): bool
    {
        return (bool) data_get($meta, 'text_fallback', false)
            || trim((string) data_get($meta, 'image_generation.fallback', data_get($meta, 'image_fallback', ''))) !== ''
            || trim((string) data_get($meta, 'video_generation.provider_fallback', '')) !== '';
    }

    private function runDowngradeUsedFromMeta(array $meta): bool
    {
        return !empty(data_get($meta, 'video_generation.extended_fallback'))
            || trim((string) data_get($meta, 'image_generation.fallback', data_get($meta, 'image_fallback', ''))) === 'local_placeholder';
    }

    private function resolveRunFinalProvider(ContentItem $item): ?string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $format = Str::lower(trim((string) $item->format));

        if ($format === 'reel') {
            $provider = $this->normalizeProvider((string) data_get($meta, 'video_generation.provider', data_get($meta, 'video_provider_last_used', data_get($meta, 'video_provider', ''))));
            return $provider !== '' ? $provider : null;
        }

        $provider = $this->normalizeProvider((string) data_get($meta, 'image_generation.provider', data_get($meta, 'image_provider', '')));

        return $provider !== '' ? $provider : null;
    }

    private function resolveVisualProvider(array $meta, bool $isVideo): string
    {
        if ($isVideo) {
            return (string) data_get($meta, 'video_generation.provider', data_get($meta, 'video_provider_last_used', data_get($meta, 'video_provider', '')));
        }

        return (string) data_get($meta, 'image_generation.provider', data_get($meta, 'image_provider', ''));
    }

    private function resolveVisualModel(array $meta, string $provider, bool $isVideo): string
    {
        if ($isVideo) {
            return trim((string) data_get($meta, 'video_generation.request_summary.model', data_get($meta, 'video_model', '')));
        }

        if ($provider === 'nanobanana') {
            return (string) config('nanobanana.image_model', 'gemini-2.5-flash-image');
        }

        return (string) config('openai.image_model', 'gpt-image-1');
    }

    private function classifyFailureMode(string $message): string
    {
        $message = Str::lower(trim($message));
        if ($message === '') {
            return '';
        }

        return match (true) {
            str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'rate limit') || str_contains($message, 'quota') => 'quota_limit',
            str_contains($message, 'moderation') || str_contains($message, 'policy') => 'moderation',
            str_contains($message, 'validation') || str_contains($message, 'invalid value') || str_contains($message, 'not supported') => 'validation',
            str_contains($message, 'dns') || str_contains($message, 'connect') || str_contains($message, 'network') || str_contains($message, 'gateway') => 'network',
            str_contains($message, 'strict_mode_no_visual_output') => 'strict_mode_no_visual_output',
            str_contains($message, 'ffmpeg') => 'media_processing',
            default => 'provider_error',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function ratePayload(int $matched, int $total, string $matchedKey, string $totalKey): array
    {
        return [
            $matchedKey => $matched,
            $totalKey => $total,
            'rate' => $total > 0 ? round($matched / $total, 4) : 0.0,
        ];
    }

    private function normalizeProvider(string $provider): string
    {
        return Str::lower(trim($provider));
    }

    private function normalizeCurrency(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }
}
