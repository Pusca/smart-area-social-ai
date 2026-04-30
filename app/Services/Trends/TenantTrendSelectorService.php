<?php

namespace App\Services\Trends;

use App\Models\ContentItem;
use App\Models\TrendSignal;

class TenantTrendSelectorService
{
    private const TOP_N = 4;

    /**
     * Selects the most relevant TrendSignal records for a specific ContentItem.
     * Filters by platform + format affinity, then scores and returns top N signals.
     *
     * @param  int  $snapshotId  Optional: restrict to a specific snapshot (0 = any)
     * @return array<int, array<string, mixed>>
     */
    public function selectFor(ContentItem $item, int $snapshotId = 0): array
    {
        $tenantId = (int) $item->tenant_id;
        $platform = strtolower(explode(',', (string) ($item->platform ?? 'instagram'))[0]);
        $format   = strtolower(trim((string) ($item->format ?? 'post')));

        $query = TrendSignal::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('estimated_relevance_score')
            ->limit(60);

        if ($snapshotId > 0) {
            $query->where('trend_snapshot_id', $snapshotId);
        }

        $signals = $query->get();

        if ($signals->isEmpty()) {
            return [];
        }

        // First pass: strict platform + format match
        $filtered = $signals
            ->filter(fn (TrendSignal $s) => $this->matchesPlatform($s, $platform))
            ->filter(fn (TrendSignal $s) => $this->matchesFormat($s, $format));

        // Fallback: relax format constraint if too few signals
        if ($filtered->count() < 2) {
            $filtered = $signals->filter(fn (TrendSignal $s) => $this->matchesPlatform($s, $platform));
        }

        // Final fallback: any signal for this tenant
        if ($filtered->isEmpty()) {
            $filtered = $signals;
        }

        return $filtered
            ->sortByDesc(fn (TrendSignal $s) => $this->compositeScore($s))
            ->take(self::TOP_N)
            ->map(fn (TrendSignal $s) => $this->toArray($s))
            ->values()
            ->all();
    }

    private function matchesPlatform(TrendSignal $signal, string $platform): bool
    {
        $signalPlatform = strtolower(trim((string) $signal->platform));

        if (in_array($signalPlatform, ['', 'all'], true) || $signalPlatform === $platform) {
            return true;
        }

        $tags = array_map('strtolower', (array) ($signal->platform_tags ?? []));

        return in_array($platform, $tags, true) || in_array('all', $tags, true);
    }

    private function matchesFormat(TrendSignal $signal, string $format): bool
    {
        $signalFormat = strtolower(trim((string) ($signal->format_type ?? '')));

        if (in_array($signalFormat, ['', 'all'], true) || $signalFormat === $format) {
            return true;
        }

        // Reel / story / video are interchangeable for video-type signals
        $videoFormats = ['reel', 'story', 'video'];
        if (in_array($signalFormat, $videoFormats, true) && in_array($format, $videoFormats, true)) {
            return true;
        }

        $tags = array_map('strtolower', (array) ($signal->format_tags ?? []));

        return in_array($format, $tags, true) || in_array('all', $tags, true);
    }

    private function compositeScore(TrendSignal $signal): float
    {
        return (float) ($signal->brand_fit_score ?? 0.0) * 0.4
            + (float) ($signal->viral_potential_score ?? 0.0) * 0.3
            + (float) ($signal->freshness_score ?? 0.0) * 0.3;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(TrendSignal $signal): array
    {
        return [
            'id'                    => (int) $signal->id,
            'topic'                 => (string) $signal->topic,
            'title'                 => (string) $signal->title,
            'summary'               => (string) $signal->summary,
            'platform'              => (string) $signal->platform,
            'format_type'           => (string) ($signal->format_type ?? 'post'),
            'hook_patterns'         => (array) ($signal->hook_patterns ?? []),
            'style_notes'           => (array) ($signal->style_notes ?? []),
            'brand_fit_score'       => round((float) ($signal->brand_fit_score ?? 0.0), 4),
            'viral_potential_score' => round((float) ($signal->viral_potential_score ?? 0.0), 4),
            'freshness_score'       => round((float) ($signal->freshness_score ?? 0.0), 4),
            'confidence_score'      => round((float) ($signal->confidence_score ?? 0.0), 4),
            'risk_flags'            => (array) ($signal->risk_flags ?? []),
            'source_type'           => (string) ($signal->source_type ?? ''),
        ];
    }
}
