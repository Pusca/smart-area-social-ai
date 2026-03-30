<?php

namespace App\Services\Trends\Adapters;

use App\Services\MemoryBuilderService;
use App\Services\Trends\TrendSourceAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class HashtagPatternTrendSourceAdapter implements TrendSourceAdapter
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder
    ) {
    }

    public function collect(array $context = []): array
    {
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return [];
        }

        $memory = $this->memoryBuilder->buildForTenant($tenantId, 40);
        $hashtags = $this->normalizeHashtags((array) ($memory['hashtags'] ?? []));
        if (empty($hashtags)) {
            return [];
        }

        $observedAt = filled($memory['last_post_date'] ?? null)
            ? Carbon::parse((string) $memory['last_post_date'])->endOfDay()
            : now();
        $limit = max(1, (int) config('trends.max_signals_per_adapter', 4));

        $signals = [];
        foreach ((array) config('trends.hashtag_pattern_artifacts', []) as $artifact) {
            if (!is_array($artifact)) {
                continue;
            }

            $topic = trim((string) ($artifact['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }

            $artifactTags = $this->normalizeHashtags(array_merge(
                (array) ($artifact['hashtags'] ?? []),
                (array) ($artifact['keywords'] ?? [])
            ));
            $matched = array_values(array_intersect($hashtags, $artifactTags));
            if (empty($matched)) {
                continue;
            }

            $matchStrength = count($matched) / max(1, count($artifactTags));
            $freshness = $this->clamp((0.68 * $this->freshnessFromObservedAt($observedAt)) + (0.32 * $matchStrength));
            $confidence = $this->clamp(0.48 + min(0.26, count($matched) * 0.08) + (((int) ($memory['posts_count'] ?? 0)) >= 4 ? 0.08 : 0.0));
            $formatType = Str::lower(trim((string) ($artifact['format_type'] ?? 'reel')));

            $signals[] = [
                'platform' => 'instagram',
                'topic' => $topic,
                'title' => trim((string) ($artifact['title'] ?? Str::title($topic))),
                'summary' => 'Segnale da pattern hashtag: il tenant sta gia orbitando attorno a questo cluster, quindi il trend e spiegabile e contestualizzato.',
                'format_type' => $formatType,
                'hook_patterns' => array_values(array_filter(array_map('strval', (array) ($artifact['hook_patterns'] ?? [])))),
                'style_notes' => array_values(array_filter(array_map('strval', (array) ($artifact['style_notes'] ?? [])))),
                'freshness_score' => round($freshness, 4),
                'confidence_score' => round($confidence, 4),
                'saturation_score' => isset($artifact['saturation_score']) ? (float) $artifact['saturation_score'] : 0.44,
                'risk_flags' => array_values(array_filter(array_map('strval', (array) ($artifact['risk_flags'] ?? [])))),
                'source_type' => 'hashtag_pattern_signal',
                'source_ref' => 'hashtag-pattern:instagram:' . Str::slug($topic),
                'niche_tags' => array_values(array_filter(array_map('strval', (array) ($artifact['niche_tags'] ?? [])))),
                'format_tags' => array_values(array_unique(array_filter(array_map('strval', [$formatType, ...((array) ($artifact['format_tags'] ?? []))])))),
                'platform_tags' => ['instagram'],
                'observed_at' => $observedAt->toDateTimeString(),
                'expires_at' => $observedAt->copy()->addHours(max(36, (int) config('social_manager.trend_brief.default_expiry_hours', 96) / 2))->toDateTimeString(),
                'meta' => [
                    'matched_hashtags' => $matched,
                    'tenant_posts_count' => (int) ($memory['posts_count'] ?? 0),
                ],
                'evidence_payload' => [
                    'artifact_type' => 'hashtag_pattern_research_artifact',
                    'matched_hashtags' => $matched,
                    'tenant_top_hashtags' => array_slice($hashtags, 0, 8),
                    'recent_titles' => array_slice((array) ($memory['recent_titles'] ?? []), 0, 3),
                ],
            ];
        }

        return array_values(array_slice($signals, 0, $limit));
    }

    /**
     * @param  array<int, string>  $hashtags
     * @return array<int, string>
     */
    private function normalizeHashtags(array $hashtags): array
    {
        return array_values(array_unique(array_filter(array_map(function ($tag): string {
            $value = Str::lower(trim((string) $tag));
            $value = ltrim($value, '#');

            return $value !== '' ? '#' . $value : '';
        }, $hashtags))));
    }

    private function freshnessFromObservedAt(Carbon $observedAt): float
    {
        $daysSince = max(0, $observedAt->diffInDays(now(), false));

        return $this->clamp(0.94 - min(0.54, $daysSince / 75));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
