<?php

namespace App\Services\Trends\Adapters;

use App\Services\MemoryBuilderService;
use App\Services\Trends\TrendSourceAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EditorialMemoryTrendSourceAdapter implements TrendSourceAdapter
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
        $themes = array_values(array_filter(array_map('strval', (array) ($memory['themes'] ?? []))));
        if (empty($themes)) {
            return [];
        }

        $observedAt = filled($memory['last_post_date'] ?? null)
            ? Carbon::parse((string) $memory['last_post_date'])->endOfDay()
            : now();
        $formats = array_values(array_filter(array_map(
            fn ($value) => Str::lower(trim((string) $value)),
            (array) data_get($memory, 'feedback_summary.preferred_formats', $context['formats'] ?? [])
        )));
        $defaultFormat = in_array('carousel', $formats, true)
            ? 'carousel'
            : (in_array('reel', $formats, true) ? 'reel' : 'post');
        $positiveSignals = array_values(array_filter(array_map('strval', (array) ($memory['positive_signals'] ?? []))));
        $avoidRules = array_values(array_filter(array_map('strval', (array) ($memory['hard_avoid_rules'] ?? []))));
        $limit = max(1, (int) config('trends.max_signals_per_adapter', 4));

        return collect(array_slice($themes, 0, $limit))
            ->values()
            ->map(function (string $theme, int $index) use ($observedAt, $defaultFormat, $memory, $positiveSignals, $avoidRules): array {
                $freshness = $this->clamp(0.82 - min(0.24, $index * 0.08) + ($this->freshnessFromObservedAt($observedAt) * 0.18));
                $confidence = $this->clamp(
                    0.44
                    + min(0.24, ((int) ($memory['posts_count'] ?? 0)) * 0.02)
                    + (!empty($positiveSignals) ? 0.08 : 0.0)
                );

                return [
                    'platform' => 'instagram',
                    'topic' => 'editorial continuity around ' . $theme,
                    'title' => 'Editorial continuity on ' . Str::title($theme),
                    'summary' => 'Segnale da memoria editoriale: il tenant sta gia lavorando su questo tema e conviene evolverlo senza ripetere i contenuti passati.',
                    'format_type' => $defaultFormat,
                    'hook_patterns' => array_values(array_filter([
                        "Apri dal problema reale legato a {$theme}.",
                        "Trasforma {$theme} in un insight pratico o in una checklist salvabile.",
                    ])),
                    'style_notes' => array_values(array_filter([
                        'Riprendi il tema ma cambia angolo, non il solo titolo.',
                        !empty($avoidRules) ? 'Mantieni presenti i feedback critici recenti del tenant.' : null,
                    ])),
                    'freshness_score' => round($freshness, 4),
                    'confidence_score' => round($confidence, 4),
                    'saturation_score' => 0.36,
                    'risk_flags' => [],
                    'source_type' => 'editorial_pattern_signal',
                    'source_ref' => 'editorial-memory:instagram:' . Str::slug($theme),
                    'niche_tags' => [$theme],
                    'format_tags' => [$defaultFormat],
                    'platform_tags' => ['instagram'],
                    'observed_at' => $observedAt->toDateTimeString(),
                    'expires_at' => $observedAt->copy()->addHours(max(48, (int) config('social_manager.trend_brief.default_expiry_hours', 96) / 2))->toDateTimeString(),
                    'meta' => [
                        'posts_count' => (int) ($memory['posts_count'] ?? 0),
                        'recent_titles_count' => count((array) ($memory['recent_titles'] ?? [])),
                    ],
                    'evidence_payload' => [
                        'artifact_type' => 'tenant_editorial_memory',
                        'theme' => $theme,
                        'recent_titles' => array_slice((array) ($memory['recent_titles'] ?? []), 0, 4),
                        'positive_signals' => array_slice($positiveSignals, 0, 4),
                        'hard_avoid_rules' => array_slice($avoidRules, 0, 4),
                    ],
                ];
            })
            ->all();
    }

    private function freshnessFromObservedAt(Carbon $observedAt): float
    {
        $daysSince = max(0, $observedAt->diffInDays(now(), false));

        return $this->clamp(0.92 - min(0.48, $daysSince / 90));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
