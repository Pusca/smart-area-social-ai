<?php

namespace App\Services\Trends;

use App\Models\TenantProfile;
use App\Models\TrendSignal;
use App\Models\TrendSnapshot;
use App\Services\Trends\Adapters\ConfigTrendSourceAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrendIntelligenceService
{
    public function __construct(
        private readonly ConfigTrendSourceAdapter $configTrendSourceAdapter
    ) {
    }

    public function refreshForTenant(int $tenantId, ?TenantProfile $profile = null, array $context = []): TrendSnapshot
    {
        $profile ??= TenantProfile::query()->where('tenant_id', $tenantId)->first();

        $latest = TrendSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->latest('observed_at')
            ->latest('id')
            ->first();

        $ttlMinutes = max(10, (int) config('trends.ttl_minutes', 240));
        if ($latest && $latest->observed_at && $latest->observed_at->gt(now()->subMinutes($ttlMinutes))) {
            return $latest->loadMissing('signals');
        }

        $signals = collect();
        foreach ($this->adapters() as $adapter) {
            $signals = $signals->merge($adapter->collect($context));
        }

        $scored = $signals
            ->map(fn (array $signal) => $this->scoreSignal($tenantId, $signal, $profile, $context))
            ->filter(fn ($signal) => is_array($signal) && trim((string) ($signal['topic'] ?? '')) !== '')
            ->values();

        return DB::transaction(function () use ($tenantId, $scored, $context): TrendSnapshot {
            $snapshot = TrendSnapshot::create([
                'tenant_id' => $tenantId,
                'status' => 'ready',
                'source_type' => $this->resolveSourceType($scored),
                'version' => 'trend_snapshot_v1',
                'summary' => $this->buildSummary($scored),
                'meta' => [
                    'context' => [
                        'platforms' => array_values(array_filter(array_map('strval', (array) ($context['platforms'] ?? [])))),
                        'formats' => array_values(array_filter(array_map('strval', (array) ($context['formats'] ?? [])))),
                        'goal' => trim((string) data_get($context, 'strategy.analysis_framework.primary_goal', '')),
                    ],
                ],
                'observed_at' => Carbon::now(),
            ]);

            foreach ($scored as $signal) {
                TrendSignal::create([
                    'trend_snapshot_id' => $snapshot->id,
                    'tenant_id' => $tenantId,
                    'platform' => (string) ($signal['platform'] ?? 'instagram'),
                    'topic' => (string) ($signal['topic'] ?? ''),
                    'format_type' => (string) ($signal['format_type'] ?? 'post'),
                    'hook_patterns' => (array) ($signal['hook_patterns'] ?? []),
                    'style_notes' => (array) ($signal['style_notes'] ?? []),
                    'freshness_score' => $signal['freshness_score'] ?? null,
                    'saturation_score' => $signal['saturation_score'] ?? null,
                    'estimated_relevance_score' => $signal['estimated_relevance_score'] ?? null,
                    'brand_fit_score' => $signal['brand_fit_score'] ?? null,
                    'novelty_score' => $signal['novelty_score'] ?? null,
                    'execution_feasibility_score' => $signal['execution_feasibility_score'] ?? null,
                    'viral_potential_score' => $signal['viral_potential_score'] ?? null,
                    'risk_flags' => (array) ($signal['risk_flags'] ?? []),
                    'source_type' => (string) ($signal['source_type'] ?? 'config_seed'),
                    'observed_at' => Carbon::parse((string) ($signal['observed_at'] ?? Carbon::now()->toDateTimeString())),
                    'meta' => (array) ($signal['meta'] ?? []),
                ]);
            }

            return $snapshot->load('signals');
        });
    }

    /**
     * @return array<int, TrendSourceAdapter>
     */
    private function adapters(): array
    {
        $adapters = [];
        foreach ((array) config('trends.adapters', []) as $adapterConfig) {
            $driver = strtolower(trim((string) Arr::get($adapterConfig, 'driver', 'config_seed')));
            if ($driver === 'config_seed') {
                $adapters[] = $this->configTrendSourceAdapter;
            }
        }

        return !empty($adapters) ? $adapters : [$this->configTrendSourceAdapter];
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scoreSignal(int $tenantId, array $signal, ?TenantProfile $profile, array $context): array
    {
        $platform = strtolower(trim((string) ($signal['platform'] ?? 'instagram')));
        $topic = trim((string) ($signal['topic'] ?? ''));
        $formatType = strtolower(trim((string) ($signal['format_type'] ?? 'post')));
        $freshness = $this->clamp((float) ($signal['freshness_score'] ?? 0.5));
        $saturation = $this->clamp((float) ($signal['saturation_score'] ?? 0.5));
        $riskFlags = array_values(array_unique(array_filter(array_map('strval', (array) ($signal['risk_flags'] ?? [])))));
        $meta = is_array($signal['meta'] ?? null) ? (array) $signal['meta'] : [];

        $industryTokens = $this->extractKeywords((string) ($profile?->industry ?? '') . ' ' . (string) ($profile?->services ?? ''));
        $audienceTokens = $this->extractKeywords((string) ($profile?->target ?? ''));
        $goalTokens = $this->extractKeywords(trim((string) data_get($context, 'strategy.analysis_framework.primary_goal', '')));
        $tone = strtolower(trim((string) ($profile?->default_tone ?? 'professionale')));
        $platformTargets = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            (array) ($context['platforms'] ?? [])
        ))));
        $formatTargets = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            (array) ($context['formats'] ?? [])
        ))));
        $assetReadiness = is_array($context['asset_readiness'] ?? null) ? (array) $context['asset_readiness'] : [];

        $industryScore = $this->keywordOverlapScore($industryTokens, (array) ($meta['industries'] ?? []));
        $audienceScore = $this->keywordOverlapScore($audienceTokens, (array) ($meta['audiences'] ?? []));
        $goalScore = $this->keywordOverlapScore($goalTokens, (array) ($meta['goals'] ?? []));
        $platformScore = empty($platformTargets) ? 0.7 : (in_array($platform, $platformTargets, true) ? 1.0 : 0.35);
        $formatScore = empty($formatTargets) ? 0.7 : (in_array($formatType, $formatTargets, true) ? 1.0 : 0.45);

        $tonePenalty = 0.0;
        $tags = array_map(fn ($value) => strtolower(trim((string) $value)), (array) ($meta['tags'] ?? []));
        if (str_contains($tone, 'profession') || str_contains($tone, 'sobr') || str_contains($tone, 'premium')) {
            if (in_array('meme', $tags, true) || in_array('casual', $tags, true) || in_array('meme_overload', $riskFlags, true)) {
                $tonePenalty += 0.18;
            }
        }

        $novelty = $this->clamp(($freshness * 0.68) + ((1 - $saturation) * 0.32));
        $riskPenalty = min(0.32, count($riskFlags) * 0.08);
        $brandFit = $this->clamp(0.34 + ($industryScore * 0.18) + ($audienceScore * 0.12) + ($goalScore * 0.10) + ($platformScore * 0.08) + ($formatScore * 0.08) - $tonePenalty - $riskPenalty);
        $execution = $this->buildExecutionFeasibilityScore($formatType, (array) ($meta['requires'] ?? []), $assetReadiness);
        $viral = $this->clamp(0.30 + ($freshness * 0.24) + ((1 - $saturation) * 0.10) + ((count((array) ($signal['hook_patterns'] ?? [])) > 1) ? 0.10 : 0.05) + ($platformScore * 0.12) + ($formatScore * 0.08) - ($riskPenalty * 0.35));
        $relevance = $this->clamp(($novelty * 0.22) + ($brandFit * 0.36) + ($execution * 0.18) + ($viral * 0.24));

        if ($saturation >= 0.85) {
            $riskFlags[] = 'trend_fatigue';
        }
        if ($brandFit < 0.45) {
            $riskFlags[] = 'low_brand_fit';
        }
        if ($execution < 0.52) {
            $riskFlags[] = 'execution_risk';
        }

        return [
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'topic' => $topic,
            'format_type' => $formatType,
            'hook_patterns' => array_values(array_filter(array_map('strval', (array) ($signal['hook_patterns'] ?? [])))),
            'style_notes' => array_values(array_filter(array_map('strval', (array) ($signal['style_notes'] ?? [])))),
            'freshness_score' => round($freshness, 4),
            'saturation_score' => round($saturation, 4),
            'estimated_relevance_score' => round($relevance, 4),
            'brand_fit_score' => round($brandFit, 4),
            'novelty_score' => round($novelty, 4),
            'execution_feasibility_score' => round($execution, 4),
            'viral_potential_score' => round($viral, 4),
            'risk_flags' => array_values(array_unique($riskFlags)),
            'source_type' => trim((string) ($signal['source_type'] ?? 'config_seed')),
            'observed_at' => (string) ($signal['observed_at'] ?? Carbon::now()->toDateTimeString()),
            'meta' => array_merge($meta, [
                'scoring' => [
                    'industry_match' => round($industryScore, 4),
                    'audience_match' => round($audienceScore, 4),
                    'goal_match' => round($goalScore, 4),
                    'platform_match' => round($platformScore, 4),
                    'format_match' => round($formatScore, 4),
                ],
            ]),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $signals
     * @return array<string, mixed>
     */
    private function buildSummary(Collection $signals): array
    {
        return [
            'version' => 'trend_snapshot_v1',
            'signals_count' => $signals->count(),
            'platform_breakdown' => $signals->countBy(fn (array $signal) => (string) ($signal['platform'] ?? 'unknown'))->all(),
            'avg_freshness_score' => round((float) $signals->avg('freshness_score'), 4),
            'avg_brand_fit_score' => round((float) $signals->avg('brand_fit_score'), 4),
            'avg_relevance_score' => round((float) $signals->avg('estimated_relevance_score'), 4),
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $signals
     */
    private function resolveSourceType(Collection $signals): string
    {
        $source = (string) $signals->pluck('source_type')->filter()->first();

        return $source !== '' ? $source : 'config_seed';
    }

    /**
     * @param  array<int, string>  $requires
     * @param  array<string, mixed>  $assetReadiness
     */
    private function buildExecutionFeasibilityScore(string $formatType, array $requires, array $assetReadiness): float
    {
        $score = in_array($formatType, ['reel', 'story', 'video'], true) ? 0.74 : 0.84;
        $requires = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $requires
        ))));

        if (in_array('overlay_support', $requires, true)) {
            $score += 0.04;
        }
        if (in_array('real_visual_anchor', $requires, true) && ((int) ($assetReadiness['images'] ?? 0)) <= 0) {
            $score -= 0.16;
        }
        if (in_array('real_location_anchor', $requires, true) && ((int) ($assetReadiness['images'] ?? 0)) <= 0) {
            $score -= 0.14;
        }
        if (in_array('person_identity', $requires, true) && (((int) ($assetReadiness['images'] ?? 0)) + ((int) ($assetReadiness['videos'] ?? 0)) <= 0)) {
            $score -= 0.18;
        }
        if (in_array($formatType, ['reel', 'story', 'video'], true) && ((int) ($assetReadiness['videos'] ?? 0)) <= 0) {
            $score -= 0.08;
        }

        return $this->clamp($score);
    }

    /**
     * @param  array<int, string>  $baseKeywords
     * @param  array<int, string>  $candidateKeywords
     */
    private function keywordOverlapScore(array $baseKeywords, array $candidateKeywords): float
    {
        $base = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $baseKeywords
        ))));
        $candidates = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $candidateKeywords
        ))));

        if (empty($base) || empty($candidates)) {
            return 0.0;
        }

        $hits = 0;
        foreach ($base as $keyword) {
            foreach ($candidates as $candidate) {
                if ($candidate === 'generic' || $candidate === 'broad') {
                    $hits += 0.25;
                    continue;
                }
                if (str_contains($keyword, $candidate) || str_contains($candidate, $keyword)) {
                    $hits += 1;
                    break;
                }
            }
        }

        return $this->clamp($hits / max(1, count($base)));
    }

    /**
     * @return array<int, string>
     */
    private function extractKeywords(string $text): array
    {
        $normalized = Str::lower($text);
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? $normalized;
        $tokens = preg_split('/\s+/', trim($normalized)) ?: [];
        $stopWords = [
            'della', 'delle', 'degli', 'dello', 'nella', 'nelle', 'sulle', 'dalla', 'dalle',
            'come', 'solo', 'anche', 'brand', 'social', 'tenant', 'servizi', 'servizio',
            'target', 'cliente', 'clienti', 'azienda', 'aziende',
        ];
        $lookup = array_fill_keys($stopWords, true);
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || strlen($token) < 4 || isset($lookup[$token])) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique(array_slice($out, 0, 12)));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}