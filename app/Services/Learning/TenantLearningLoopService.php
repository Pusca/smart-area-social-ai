<?php

namespace App\Services\Learning;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\GenerationRun;
use App\Models\SocialPublication;
use App\Models\TenantProfile;
use App\Services\Feedback\TenantFeedbackSignalSynthesisService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantLearningLoopService
{
    public function __construct(
        private readonly TenantFeedbackSignalSynthesisService $feedbackSignalSynthesis
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshForTenant(int $tenantId): array
    {
        $learning = $this->buildForTenant($tenantId);

        TenantProfile::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            ['learning_preferences' => $learning]
        );

        return $learning;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForTenant(int $tenantId): array
    {
        $windowDays = max(7, (int) config('social_manager.learning.window_days', 90));
        $minEvents = max(1, (int) config('social_manager.learning.min_events_for_bias', 2));
        $maxPreferred = max(1, (int) config('social_manager.learning.max_preferred_items', 6));
        $maxUnderperforming = max(1, (int) config('social_manager.learning.max_underperforming_items', 5));
        $maxProviderPaths = max(1, (int) config('social_manager.learning.max_provider_paths', 5));

        $feedbackSummary = $this->feedbackSignalSynthesis->buildForTenant($tenantId, 60);
        $contentItems = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '>=', now()->subDays($windowDays))
            ->get(['id', 'format', 'platform', 'ai_meta', 'ai_cta', 'status', 'published_at']);

        $generationRuns = GenerationRun::query()
            ->where('tenant_id', $tenantId)
            ->where('started_at', '>=', now()->subDays($windowDays))
            ->get(['id', 'content_item_id', 'status', 'failure_mode', 'final_provider', 'quality_scorecard', 'publish_gate', 'effective_output']);

        $publications = SocialPublication::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->get(['id', 'content_item_id', 'platform', 'status', 'provider', 'response_meta']);

        $dislikes = ContentFeedbackEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('sentiment', ContentFeedbackEntry::SENTIMENT_DISLIKE)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->get(['content_item_id', 'normalized_category', 'severity', 'scores']);

        $likedIds = collect((array) ($feedbackSummary['liked_examples'] ?? []))
            ->map(fn ($row) => (int) ($row['content_item_id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $likedItems = $contentItems->whereIn('id', $likedIds->all())->values();

        $preferredHookFamilies = $this->topValues(
            $likedItems->map(function (ContentItem $item): string {
                $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

                return (string) data_get(
                    $meta,
                    'viral_angle.mechanic',
                    data_get($meta, 'content_strategy.strategy_type', data_get($meta, 'item_brain.content_strategy_type', ''))
                );
            }),
            $maxPreferred,
            $minEvents
        );

        $preferredCtaStyles = $this->topValues(
            $likedItems->map(function (ContentItem $item): string {
                $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

                return (string) data_get($meta, 'hook_meta.cta_mode', '');
            }),
            $maxPreferred,
            $minEvents
        );

        $formatSignals = $this->formatSignals($contentItems, $dislikes);
        $underperformingFormats = collect($formatSignals)
            ->filter(fn (array $row): bool => ((int) ($row['negative_signals'] ?? 0)) >= $minEvents)
            ->sortByDesc(fn (array $row): int => (int) ($row['negative_signals'] ?? 0))
            ->take($maxUnderperforming)
            ->values()
            ->all();

        return [
            'version' => (string) config('social_manager.learning.version', 'tenant_learning_v1'),
            'generated_at' => now()->toDateTimeString(),
            'window_days' => $windowDays,
            'preferred_hook_families' => $preferredHookFamilies,
            'preferred_cta_styles' => $preferredCtaStyles,
            'formats_that_underperform' => $underperformingFormats,
            'provider_paths_to_avoid_for_identity_heavy' => $this->providerPathsToAvoid($contentItems, $generationRuns, $maxProviderPaths, $minEvents),
            'trend_outcomes' => $this->trendOutcomes($contentItems, $likedIds, $dislikes),
            'publication_summary' => $this->publicationSummary($publications),
            'feedback_summary' => [
                'positive_signals' => array_values(array_slice((array) ($feedbackSummary['positive_signals'] ?? []), 0, 8)),
                'hard_avoid_rules' => array_values(array_slice((array) ($feedbackSummary['hard_avoid_rules'] ?? []), 0, 8)),
                'priority_categories' => array_values(array_slice((array) ($feedbackSummary['priority_categories'] ?? []), 0, 6)),
            ],
            'sources' => [
                'feedback_entries' => (int) ($feedbackSummary['total_count'] ?? 0),
                'generation_runs' => $generationRuns->count(),
                'publications' => $publications->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, ContentItem>  $contentItems
     * @param  Collection<int, ContentFeedbackEntry>  $dislikes
     * @return array<int, array<string, mixed>>
     */
    private function formatSignals(Collection $contentItems, Collection $dislikes): array
    {
        $negativeByItem = $dislikes->groupBy('content_item_id');

        return $contentItems
            ->groupBy(fn (ContentItem $item) => Str::lower(trim((string) $item->format)))
            ->map(function (Collection $items, string $format) use ($negativeByItem): array {
                $negativeSignals = $items->sum(fn (ContentItem $item) => $negativeByItem->get($item->id, collect())->count());

                return [
                    'format' => $format,
                    'content_count' => $items->count(),
                    'negative_signals' => $negativeSignals,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ContentItem>  $contentItems
     * @param  Collection<int, GenerationRun>  $generationRuns
     * @return array<int, array<string, mixed>>
     */
    private function providerPathsToAvoid(Collection $contentItems, Collection $generationRuns, int $limit, int $minEvents): array
    {
        $identityHeavyLookup = $contentItems
            ->keyBy('id')
            ->map(function (ContentItem $item): bool {
                $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

                return !empty((array) data_get($meta, 'asset_identity.slots', []));
            });

        return $generationRuns
            ->filter(function (GenerationRun $run) use ($identityHeavyLookup): bool {
                $contentItemId = (int) ($run->content_item_id ?? 0);

                return $contentItemId > 0
                    && (bool) ($identityHeavyLookup[$contentItemId] ?? false)
                    && (
                        (string) ($run->status ?? '') === 'failed'
                        || in_array((string) data_get($run->publish_gate, 'decision', ''), ['blocked', 'manual_review_required'], true)
                    );
            })
            ->groupBy(function (GenerationRun $run): string {
                return trim((string) ($run->final_provider ?? data_get($run->effective_output, 'video_provider', data_get($run->effective_output, 'image_provider', 'unknown'))));
            })
            ->map(function (Collection $runs, string $provider): array {
                $failureModes = $runs
                    ->map(fn (GenerationRun $run) => trim((string) ($run->failure_mode ?? '')))
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->keys()
                    ->take(3)
                    ->values()
                    ->all();

                return [
                    'provider' => $provider !== '' ? $provider : 'unknown',
                    'failures' => $runs->count(),
                    'failure_modes' => $failureModes,
                ];
            })
            ->filter(fn (array $row): bool => (int) ($row['failures'] ?? 0) >= $minEvents)
            ->sortByDesc(fn (array $row): int => (int) ($row['failures'] ?? 0))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ContentItem>  $contentItems
     * @param  Collection<int, int>  $likedIds
     * @param  Collection<int, ContentFeedbackEntry>  $dislikes
     * @return array<string, mixed>
     */
    private function trendOutcomes(Collection $contentItems, Collection $likedIds, Collection $dislikes): array
    {
        $dislikedIds = $dislikes
            ->map(fn (ContentFeedbackEntry $entry) => (int) $entry->content_item_id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $trendAware = $contentItems->filter(function (ContentItem $item): bool {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $strategyType = trim((string) data_get($meta, 'content_strategy.strategy_type', data_get($meta, 'item_brain.content_strategy_type', '')));

            return $strategyType === 'trend-aware'
                || !empty((array) data_get($meta, 'item_brain.trend_opportunity', []));
        });

        return [
            'matched_audience_response' => $trendAware->whereIn('id', $likedIds->all())->count(),
            'missed_audience_response' => $trendAware->whereIn('id', $dislikedIds->all())->count(),
            'latest_topics' => $trendAware
                ->map(function (ContentItem $item): string {
                    $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

                    return trim((string) data_get($meta, 'item_brain.trend_basis.topic', data_get($meta, 'item_brain.trend_opportunity.topic', '')));
                })
                ->filter()
                ->unique()
                ->take(6)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, SocialPublication>  $publications
     * @return array<string, mixed>
     */
    private function publicationSummary(Collection $publications): array
    {
        return [
            'published_total' => $publications->where('status', 'published')->count(),
            'failed_total' => $publications->where('status', 'failed')->count(),
            'scheduled_total' => $publications->where('status', 'scheduled')->count(),
            'platforms' => $publications
                ->map(fn (SocialPublication $publication) => trim((string) $publication->platform))
                ->filter()
                ->countBy()
                ->sortDesc()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, string>  $values
     * @return array<int, string>
     */
    private function topValues(Collection $values, int $limit, int $minEvents): array
    {
        return $values
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->countBy()
            ->filter(fn (int $count) => $count >= $minEvents)
            ->sortDesc()
            ->keys()
            ->take($limit)
            ->values()
            ->all();
    }
}
