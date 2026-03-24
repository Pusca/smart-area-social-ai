<?php

namespace App\Services\Trends;

use App\Models\TenantProfile;
use App\Models\TrendSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrendOpportunitySynthesisService
{
    public function __construct(
        private readonly TrendIntelligenceService $trendIntelligence
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildForTenant(int $tenantId, ?TenantProfile $profile = null, array $context = []): array
    {
        if (!config('trends.enabled', true)) {
            return [
                'enabled' => false,
                'version' => 'trend_opportunities_v1',
                'summary' => ['opportunities_count' => 0],
                'opportunities' => [],
            ];
        }

        $snapshot = $this->trendIntelligence->refreshForTenant($tenantId, $profile, $context);
        $snapshot->loadMissing('signals');

        $signals = $snapshot->signals instanceof Collection
            ? $snapshot->signals
            : collect($snapshot->signals);

        $eligibleSignals = $signals
            ->filter(fn (TrendSignal $signal) => $this->isEligibleSignal($signal))
            ->sortByDesc(fn (TrendSignal $signal) => (float) ($signal->estimated_relevance_score ?? 0.0))
            ->values();

        if ($eligibleSignals->isEmpty()) {
            $eligibleSignals = $signals
                ->reject(fn (TrendSignal $signal) => $this->hasBlockedRiskFlag($signal))
                ->sortByDesc(fn (TrendSignal $signal) => (float) ($signal->estimated_relevance_score ?? 0.0))
                ->values();
        }

        $opportunities = $eligibleSignals
            ->take(max(1, (int) config('trends.max_opportunities', 6)))
            ->map(fn (TrendSignal $signal) => $this->buildOpportunity($signal))
            ->all();

        $summary = [
            'version' => 'trend_opportunities_v1',
            'snapshot_id' => (int) $snapshot->id,
            'snapshot_observed_at' => optional($snapshot->observed_at)->toDateTimeString(),
            'opportunities_count' => count($opportunities),
            'platform_breakdown' => collect($opportunities)->countBy(fn (array $item) => (string) ($item['platform'] ?? 'unknown'))->all(),
            'avg_brand_fit_score' => round((float) collect($opportunities)->avg('brand_fit_score'), 4),
            'avg_viral_potential_score' => round((float) collect($opportunities)->avg('viral_potential_score'), 4),
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];

        $snapshot->forceFill([
            'opportunity_summary' => $summary,
        ])->save();

        return [
            'enabled' => true,
            'version' => 'trend_opportunities_v1',
            'snapshot_id' => (int) $snapshot->id,
            'source_type' => (string) ($snapshot->source_type ?? 'config_seed'),
            'summary' => $summary,
            'opportunities' => $opportunities,
            'platforms' => array_values(array_keys((array) ($summary['platform_breakdown'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildPlanningBrief(int $tenantId, array $strategy = [], array $context = []): array
    {
        $trendIntelligence = (array) ($strategy['trend_intelligence'] ?? []);
        if (empty($trendIntelligence)) {
            $trendIntelligence = $this->buildForTenant($tenantId, null, $context);
        }

        $opportunities = array_values(array_filter((array) ($trendIntelligence['opportunities'] ?? []), fn ($item) => is_array($item)));

        return [
            'enabled' => (bool) ($trendIntelligence['enabled'] ?? !empty($opportunities)),
            'snapshot_id' => $trendIntelligence['snapshot_id'] ?? null,
            'items' => array_map(function (array $opportunity): array {
                return [
                    'title' => (string) ($opportunity['headline'] ?? $opportunity['topic'] ?? 'Trend insight'),
                    'topic' => (string) ($opportunity['topic'] ?? ''),
                    'platform' => (string) ($opportunity['platform'] ?? 'instagram'),
                    'format_type' => (string) ($opportunity['format_type'] ?? 'post'),
                    'hook_patterns' => (array) ($opportunity['hook_patterns'] ?? []),
                    'style_notes' => (array) ($opportunity['style_notes'] ?? []),
                    'brand_safe_hook' => (string) ($opportunity['brand_safe_hook'] ?? ''),
                    'recommended_angle' => (string) ($opportunity['recommended_angle'] ?? ''),
                    'why_it_fits' => (string) ($opportunity['why_it_fits'] ?? ''),
                    'risk_flags' => (array) ($opportunity['risk_flags'] ?? []),
                    'freshness_score' => $opportunity['freshness_score'] ?? null,
                    'saturation_score' => $opportunity['saturation_score'] ?? null,
                    'estimated_relevance_score' => $opportunity['estimated_relevance_score'] ?? null,
                    'brand_fit_score' => $opportunity['brand_fit_score'] ?? null,
                    'novelty_score' => $opportunity['novelty_score'] ?? null,
                    'execution_feasibility_score' => $opportunity['execution_feasibility_score'] ?? null,
                    'viral_potential_score' => $opportunity['viral_potential_score'] ?? null,
                    'source' => (string) ($opportunity['source_type'] ?? 'config_seed'),
                    'source_type' => (string) ($opportunity['source_type'] ?? 'config_seed'),
                    'observed_at' => (string) ($opportunity['observed_at'] ?? ''),
                    'link' => (string) ($opportunity['source_url'] ?? ''),
                    'trend_opportunity' => $opportunity,
                ];
            }, $opportunities),
        ];
    }

    private function isEligibleSignal(TrendSignal $signal): bool
    {
        $brandFit = (float) ($signal->brand_fit_score ?? 0.0);
        $relevance = (float) ($signal->estimated_relevance_score ?? 0.0);

        if ($this->hasBlockedRiskFlag($signal)) {
            return false;
        }

        return $brandFit >= (float) config('trends.min_brand_fit_score', 0.55)
            && $relevance >= (float) config('trends.min_relevance_score', 0.58);
    }

    private function hasBlockedRiskFlag(TrendSignal $signal): bool
    {
        $riskFlags = array_map(
            fn ($value) => strtolower(trim((string) $value)),
            (array) ($signal->risk_flags ?? [])
        );
        $blockedFlags = array_map(
            fn ($value) => strtolower(trim((string) $value)),
            (array) config('trends.risk_block_flags', [])
        );

        return !empty(array_intersect($riskFlags, $blockedFlags));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOpportunity(TrendSignal $signal): array
    {
        $topic = trim((string) $signal->topic);
        $platform = strtolower(trim((string) $signal->platform));
        $formatType = strtolower(trim((string) ($signal->format_type ?? 'post')));
        $hookPatterns = array_values(array_filter(array_map('strval', (array) ($signal->hook_patterns ?? []))));
        $styleNotes = array_values(array_filter(array_map('strval', (array) ($signal->style_notes ?? []))));
        $riskFlags = array_values(array_filter(array_map('strval', (array) ($signal->risk_flags ?? []))));

        $headline = Str::title($topic);
        $brandSafeHook = $hookPatterns[0] ?? 'Apri con un gancio moderno ma coerente con il brand.';
        $styleSummary = implode('; ', array_slice($styleNotes, 0, 2));
        $whyItFits = 'Trend fresco con buon fit brand e applicazione eseguibile senza forzare il tono del tenant.';
        if ((float) ($signal->brand_fit_score ?? 0.0) >= 0.8) {
            $whyItFits = 'Trend molto coerente con tono, settore e obiettivi del tenant.';
        }

        return [
            'signal_id' => (int) $signal->id,
            'snapshot_id' => (int) $signal->trend_snapshot_id,
            'headline' => $headline,
            'platform' => $platform,
            'topic' => $topic,
            'format_type' => $formatType,
            'hook_patterns' => $hookPatterns,
            'style_notes' => $styleNotes,
            'brand_safe_hook' => $brandSafeHook,
            'recommended_angle' => "Tratta '{$topic}' come format o meccanica, non come trend da copiare: traduci il concetto in una scena o un insight proprietario del brand.",
            'recommended_format' => "Preferisci {$formatType} su {$platform} con esecuzione premium e leggibile.",
            'style_summary' => $styleSummary,
            'why_it_fits' => $whyItFits,
            'avoid_notes' => array_values(array_filter(array_merge(
                !empty($riskFlags) ? ['Evita le versioni tossiche o inflazionate del trend.'] : [],
                ['Mantieni professionalita, ancore reali e identita del brand.']
            ))),
            'freshness_score' => $this->normalize((float) ($signal->freshness_score ?? 0.0)),
            'saturation_score' => $this->normalize((float) ($signal->saturation_score ?? 0.0)),
            'estimated_relevance_score' => $this->normalize((float) ($signal->estimated_relevance_score ?? 0.0)),
            'brand_fit_score' => $this->normalize((float) ($signal->brand_fit_score ?? 0.0)),
            'novelty_score' => $this->normalize((float) ($signal->novelty_score ?? 0.0)),
            'execution_feasibility_score' => $this->normalize((float) ($signal->execution_feasibility_score ?? 0.0)),
            'viral_potential_score' => $this->normalize((float) ($signal->viral_potential_score ?? 0.0)),
            'risk_flags' => $riskFlags,
            'source_type' => (string) ($signal->source_type ?? 'config_seed'),
            'observed_at' => optional($signal->observed_at)->toDateTimeString(),
            'meta' => is_array($signal->meta) ? $signal->meta : [],
        ];
    }

    private function normalize(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 4);
    }
}