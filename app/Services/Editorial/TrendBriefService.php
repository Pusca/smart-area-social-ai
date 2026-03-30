<?php

namespace App\Services\Editorial;

use App\Models\TrendBrief;
use App\Models\TrendSnapshot;
use App\Models\TenantProfile;
use App\Services\Trends\TrendOpportunitySynthesisService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrendBriefService
{
    public function __construct(
        private readonly TrendOpportunitySynthesisService $trendOpportunitySynthesis
    ) {
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function getBriefForTenant(int $tenantId, ?TenantProfile $profile = null, array $strategy = [], array $context = []): array
    {
        $snapshot = $this->trendOpportunitySynthesis->buildPlanningBrief($tenantId, $strategy, $context);
        $trendSnapshot = null;
        if ((int) ($snapshot['snapshot_id'] ?? 0) > 0) {
            $trendSnapshot = TrendSnapshot::query()
                ->with('signals')
                ->find((int) $snapshot['snapshot_id']);
        }

        $signals = $trendSnapshot?->signals instanceof Collection
            ? $trendSnapshot->signals
            : collect($trendSnapshot?->signals ?? []);

        $brief = [
            'version' => (string) config('social_manager.trend_brief.version', 'trend_brief_v1'),
            'brief_key' => 'tenant:' . $tenantId . ':default',
            'tenant_id' => $tenantId,
            'snapshot_id' => (int) ($snapshot['snapshot_id'] ?? 0),
            'generated_at' => Carbon::now()->toDateTimeString(),
            'current_relevant_themes' => $this->currentRelevantThemes($snapshot),
            'recommended_post_angles' => $this->recommendedPostAngles($snapshot),
            'recommended_reel_structures' => $this->recommendedReelStructures($snapshot),
            'recommended_hook_patterns' => $this->recommendedHookPatterns($snapshot),
            'recommended_cta_styles' => $this->recommendedCtaStyles($snapshot, $profile),
            'trends_to_avoid' => $this->trendsToAvoid($snapshot),
            'signals_freshness' => $this->signalsFreshness($signals),
            'freshness_score' => $this->resolveFreshnessScore($snapshot, $signals),
            'confidence_score' => $this->resolveConfidenceScore($snapshot, $signals),
            'expires_at' => $this->resolveExpiry($signals),
            'snapshot' => $snapshot,
            'summary' => [
                'signals_count' => $signals->count(),
                'fresh_signals' => $signals->filter(fn ($signal) => $this->signalFreshnessBucket($signal) === 'fresh')->count(),
                'expiring_signals' => $signals->filter(fn ($signal) => $this->signalFreshnessBucket($signal) === 'expiring')->count(),
                'stale_signals' => $signals->filter(fn ($signal) => $this->signalFreshnessBucket($signal) === 'stale')->count(),
            ],
        ];

        TrendBrief::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'brief_key' => 'tenant:' . $tenantId . ':default',
                'source_snapshot_id' => (int) ($brief['snapshot_id'] ?? 0) ?: null,
                'snapshot' => $snapshot,
                'summary' => $brief['summary'],
                'freshness_score' => $brief['freshness_score'],
                'confidence_score' => $brief['confidence_score'],
                'expires_at' => $brief['expires_at'] !== null ? Carbon::parse((string) $brief['expires_at']) : null,
                'fetched_at' => Carbon::now(),
            ]
        );

        return $brief;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function currentRelevantThemes(array $snapshot): array
    {
        return collect((array) ($snapshot['items'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->take((int) config('social_manager.trend_brief.max_themes', 6))
            ->map(fn (array $item): array => [
                'topic' => (string) ($item['topic'] ?? ''),
                'title' => (string) ($item['title'] ?? $item['topic'] ?? ''),
                'platform' => (string) ($item['platform'] ?? 'instagram'),
                'format_type' => (string) ($item['format_type'] ?? 'post'),
                'brand_fit_score' => $item['brand_fit_score'] ?? null,
                'freshness_score' => $item['freshness_score'] ?? null,
                'source_type' => (string) ($item['source_type'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function recommendedPostAngles(array $snapshot): array
    {
        return collect((array) ($snapshot['items'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->take((int) config('social_manager.trend_brief.max_angles', 6))
            ->map(fn (array $item): array => [
                'topic' => (string) ($item['topic'] ?? ''),
                'angle' => (string) ($item['recommended_angle'] ?? ''),
                'format_type' => (string) ($item['format_type'] ?? 'post'),
                'why_it_fits' => (string) ($item['why_it_fits'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function recommendedReelStructures(array $snapshot): array
    {
        return collect((array) ($snapshot['items'] ?? []))
            ->filter(fn ($item) => is_array($item) && in_array((string) ($item['format_type'] ?? ''), ['reel', 'video', 'story'], true))
            ->take((int) config('social_manager.trend_brief.max_reel_structures', 4))
            ->map(fn (array $item): array => [
                'topic' => (string) ($item['topic'] ?? ''),
                'structure' => implode(' | ', array_filter([
                    (string) ($item['brand_safe_hook'] ?? ''),
                    (string) ($item['recommended_angle'] ?? ''),
                    (string) ($item['recommended_format'] ?? ''),
                ])),
                'platform' => (string) ($item['platform'] ?? 'instagram'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function recommendedHookPatterns(array $snapshot): array
    {
        return collect((array) ($snapshot['items'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->flatMap(function (array $item): array {
                return collect((array) ($item['hook_patterns'] ?? []))
                    ->map(fn ($pattern): array => [
                        'topic' => (string) ($item['topic'] ?? ''),
                        'pattern' => trim((string) $pattern),
                        'platform' => (string) ($item['platform'] ?? 'instagram'),
                    ])
                    ->filter(fn (array $row): bool => $row['pattern'] !== '')
                    ->values()
                    ->all();
            })
            ->take((int) config('social_manager.trend_brief.max_hook_patterns', 8))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function recommendedCtaStyles(array $snapshot, ?TenantProfile $profile): array
    {
        $styles = collect((array) ($snapshot['items'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                $formatType = (string) ($item['format_type'] ?? 'post');

                return [
                    'topic' => (string) ($item['topic'] ?? ''),
                    'style' => in_array($formatType, ['reel', 'story', 'video'], true)
                        ? 'comment_or_dm_soft'
                        : 'save_or_reply_consultative',
                    'reason' => (string) ($item['why_it_fits'] ?? ''),
                ];
            })
            ->take((int) config('social_manager.trend_brief.max_cta_styles', 5))
            ->values();

        if ($styles->isEmpty() && filled($profile?->cta)) {
            $styles->push([
                'topic' => 'brand_default',
                'style' => 'brand_default_cta',
                'reason' => trim((string) $profile?->cta),
            ]);
        }

        return $styles->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function trendsToAvoid(array $snapshot): array
    {
        return collect((array) ($snapshot['items'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->flatMap(fn (array $item): array => array_values(array_filter(array_map('strval', (array) ($item['risk_flags'] ?? [])))))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $signals
     * @return array<string, int>
     */
    private function signalsFreshness(Collection $signals): array
    {
        return [
            'fresh' => $signals->filter(fn ($signal) => $this->signalFreshnessBucket($signal) === 'fresh')->count(),
            'expiring' => $signals->filter(fn ($signal) => $this->signalFreshnessBucket($signal) === 'expiring')->count(),
            'stale' => $signals->filter(fn ($signal) => $this->signalFreshnessBucket($signal) === 'stale')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, mixed>  $signals
     */
    private function resolveFreshnessScore(array $snapshot, Collection $signals): ?float
    {
        $value = data_get($snapshot, 'summary.avg_freshness_score');
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }

        $avg = $signals->avg('freshness_score');

        return is_numeric($avg) ? round((float) $avg, 4) : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, mixed>  $signals
     */
    private function resolveConfidenceScore(array $snapshot, Collection $signals): ?float
    {
        $value = data_get($snapshot, 'summary.avg_confidence_score');
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }

        $avg = $signals->avg('confidence_score');

        return is_numeric($avg) ? round((float) $avg, 4) : null;
    }

    /**
     * @param  Collection<int, mixed>  $signals
     */
    private function resolveExpiry(Collection $signals): ?string
    {
        $expiresAt = $signals
            ->map(function ($signal) {
                $expires = data_get($signal, 'expires_at');

                return $expires instanceof Carbon ? $expires : (filled($expires) ? Carbon::parse((string) $expires) : null);
            })
            ->filter()
            ->sort()
            ->first();

        if ($expiresAt instanceof Carbon) {
            return $expiresAt->toDateTimeString();
        }

        return Carbon::now()
            ->addHours((int) config('social_manager.trend_brief.default_expiry_hours', 96))
            ->toDateTimeString();
    }

    private function signalFreshnessBucket(mixed $signal): string
    {
        $expiresAt = data_get($signal, 'expires_at');
        $expiresAt = $expiresAt instanceof Carbon ? $expiresAt : (filled($expiresAt) ? Carbon::parse((string) $expiresAt) : null);

        if (!$expiresAt instanceof Carbon) {
            return 'stale';
        }

        $freshHours = max(1, (int) config('social_manager.trend_brief.fresh_signal_hours', 24));
        $expiringHours = max($freshHours + 1, (int) config('social_manager.trend_brief.expiring_signal_hours', 72));
        $hoursLeft = now()->diffInHours($expiresAt, false);

        if ($hoursLeft >= $freshHours) {
            return 'fresh';
        }

        if ($hoursLeft > 0 && $hoursLeft < $expiringHours) {
            return 'expiring';
        }

        return 'stale';
    }
}
