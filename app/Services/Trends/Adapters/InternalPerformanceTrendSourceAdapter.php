<?php

namespace App\Services\Trends\Adapters;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\SocialPublication;
use App\Services\Trends\TrendSourceAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InternalPerformanceTrendSourceAdapter implements TrendSourceAdapter
{
    public function collect(array $context = []): array
    {
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return [];
        }

        $windowDays = max(7, (int) config('trends.performance_window_days', 90));
        $limit = max(1, (int) config('trends.max_signals_per_adapter', 4));

        $items = ContentItem::query()
            ->with([
                'feedbackEntries:id,content_item_id,sentiment,reason',
                'publications:id,content_item_id,platform,status,response_meta,published_at,created_at',
            ])
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '>=', now()->subDays($windowDays))
            ->latest('updated_at')
            ->limit(36)
            ->get(['id', 'title', 'platform', 'format', 'status', 'published_at', 'updated_at', 'ai_meta']);

        if ($items->isEmpty()) {
            return [];
        }

        $grouped = $items
            ->map(fn (ContentItem $item) => $this->mapItemToSignalCandidate($item))
            ->filter(fn ($row) => is_array($row) && ($row['platform'] ?? '') === 'instagram')
            ->groupBy(fn (array $row) => (string) ($row['group_key'] ?? ''))
            ->filter(fn (Collection $rows, string $groupKey) => $groupKey !== '');

        return $grouped
            ->map(fn (Collection $rows) => $this->buildSignal($rows))
            ->filter(fn ($signal) => is_array($signal))
            ->sortByDesc(fn (array $signal) => (float) ($signal['freshness_score'] ?? 0.0) + (float) ($signal['confidence_score'] ?? 0.0))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapItemToSignalCandidate(ContentItem $item): ?array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $platform = in_array('instagram', $item->platforms(), true) ? 'instagram' : '';
        if ($platform === '') {
            $publicationPlatform = $item->publications
                ->map(fn (SocialPublication $publication) => Str::lower(trim((string) $publication->platform)))
                ->first(fn (string $value) => $value === 'instagram');
            $platform = $publicationPlatform ?: '';
        }

        if ($platform === '') {
            return null;
        }

        $topic = trim((string) data_get(
            $meta,
            'item_brain.trend_basis.topic',
            data_get(
                $meta,
                'item_brain.trend_opportunity.topic',
                data_get($meta, 'content_strategy.narrative_angle', '')
            )
        ));
        if ($topic === '') {
            $topic = trim((string) $item->title);
        }
        if ($topic === '') {
            $topic = 'high-performing ' . Str::lower(trim((string) ($item->format ?: 'post'))) . ' pattern';
        }

        $likes = $item->feedbackEntries->where('sentiment', ContentFeedbackEntry::SENTIMENT_LIKE)->count();
        $dislikes = $item->feedbackEntries->where('sentiment', ContentFeedbackEntry::SENTIMENT_DISLIKE)->count();
        $latestPublication = $item->publications
            ->sortByDesc(fn (SocialPublication $publication) => $publication->published_at ?? $publication->created_at)
            ->first();
        $responseMeta = is_array($latestPublication?->response_meta) ? $latestPublication->response_meta : [];

        $metrics = [
            'impressions' => max(
                (int) data_get($responseMeta, 'impressions', 0),
                (int) data_get($responseMeta, 'reach', 0),
            ),
            'engagement' => max(
                0,
                (int) data_get($responseMeta, 'likes', 0)
                + (int) data_get($responseMeta, 'comments', 0)
                + (int) data_get($responseMeta, 'saves', 0)
                + (int) data_get($responseMeta, 'shares', 0)
                + (int) data_get($responseMeta, 'replies', 0)
            ),
        ];

        $performanceScore = $this->clamp(
            0.34
            + min(0.24, $metrics['impressions'] / 6000)
            + min(0.18, $metrics['engagement'] / 120)
            + ($likes * 0.10)
            - ($dislikes * 0.12)
            + (($item->status === 'published' || $latestPublication?->status === 'published') ? 0.10 : 0.0)
        );

        $observedAt = $item->published_at ?: $item->updated_at ?: now();
        $hookPatterns = array_values(array_filter(array_map('strval', [
            data_get($meta, 'hook_meta.main_hook'),
            data_get($meta, 'hook_meta.alternative_hook'),
            data_get($meta, 'storyboard_meta.scene_list.0.overlay_text_segment'),
        ])));

        return [
            'group_key' => $platform . '|' . Str::lower(trim((string) ($item->format ?: 'post'))) . '|' . Str::lower($topic),
            'platform' => $platform,
            'topic' => Str::limit($topic, 120, ''),
            'title' => Str::limit($topic, 140, ''),
            'format_type' => Str::lower(trim((string) ($item->format ?: 'post'))),
            'hook_patterns' => $hookPatterns,
            'style_notes' => [
                'Parti da una prova o da un dettaglio reale gia validato dal tenant.',
                'Mantieni l esecuzione vicina ai pattern che hanno gia retto meglio.',
            ],
            'performance_score' => round($performanceScore, 4),
            'likes' => $likes,
            'dislikes' => $dislikes,
            'metrics' => $metrics,
            'observed_at' => $observedAt instanceof Carbon ? $observedAt : Carbon::parse((string) $observedAt),
            'item_id' => (int) $item->id,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function buildSignal(Collection $rows): ?array
    {
        $first = $rows->first();
        if (!is_array($first)) {
            return null;
        }

        $latestObservedAt = $rows
            ->map(fn (array $row) => $row['observed_at'] instanceof Carbon ? $row['observed_at'] : Carbon::parse((string) $row['observed_at']))
            ->sortDesc()
            ->first();
        if (!$latestObservedAt instanceof Carbon) {
            $latestObservedAt = now();
        }

        $daysSince = max(0, $latestObservedAt->diffInDays(now(), false));
        $freshness = $this->clamp(0.96 - min(0.56, $daysSince / 90));
        $confidence = $this->clamp(
            0.42
            + min(0.24, ($rows->count() - 1) * 0.10)
            + ($rows->sum('likes') > 0 ? 0.08 : 0.0)
            + ($rows->sum(fn (array $row) => (int) data_get($row, 'metrics.impressions', 0)) > 0 ? 0.10 : 0.0)
        );

        $avgPerformance = (float) $rows->avg('performance_score');
        $saturation = $this->clamp(0.26 + min(0.24, ($rows->count() - 1) * 0.08));

        return [
            'platform' => (string) $first['platform'],
            'topic' => (string) $first['topic'],
            'title' => (string) $first['title'],
            'summary' => 'Segnale interno: questo pattern ha gia raccolto riscontri positivi o metriche utili nel tenant, quindi vale la pena riprenderlo con una versione aggiornata.',
            'format_type' => (string) $first['format_type'],
            'hook_patterns' => array_values($rows->flatMap(fn (array $row) => (array) ($row['hook_patterns'] ?? []))->filter()->unique()->take(3)->all()),
            'style_notes' => (array) $first['style_notes'],
            'freshness_score' => round($freshness, 4),
            'confidence_score' => round($confidence, 4),
            'saturation_score' => round($saturation, 4),
            'risk_flags' => [],
            'source_type' => 'internal_performance_signal',
            'source_ref' => 'performance:instagram:' . Str::slug((string) $first['topic']),
            'niche_tags' => [],
            'format_tags' => [(string) $first['format_type']],
            'platform_tags' => ['instagram'],
            'observed_at' => $latestObservedAt->toDateTimeString(),
            'expires_at' => $latestObservedAt->copy()->addHours(max(24, (int) config('social_manager.trend_brief.default_expiry_hours', 96) / 2))->toDateTimeString(),
            'meta' => [
                'avg_performance_score' => round($avgPerformance, 4),
                'sample_size' => $rows->count(),
                'likes_count' => $rows->sum('likes'),
                'dislikes_count' => $rows->sum('dislikes'),
            ],
            'evidence_payload' => [
                'sample_item_ids' => $rows->pluck('item_id')->take(6)->values()->all(),
                'sample_size' => $rows->count(),
                'avg_performance_score' => round($avgPerformance, 4),
                'metric_totals' => [
                    'impressions' => $rows->sum(fn (array $row) => (int) data_get($row, 'metrics.impressions', 0)),
                    'engagement' => $rows->sum(fn (array $row) => (int) data_get($row, 'metrics.engagement', 0)),
                ],
                'feedback' => [
                    'likes' => $rows->sum('likes'),
                    'dislikes' => $rows->sum('dislikes'),
                ],
            ],
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
