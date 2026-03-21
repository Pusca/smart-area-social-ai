<?php

namespace App\Services\Feedback;

use App\Models\ContentFeedbackEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantFeedbackSignalSynthesisService
{
    public function buildForTenant(int $tenantId, int $limit = 40): array
    {
        $rows = ContentFeedbackEntry::query()
            ->with([
                'contentItem:id,title,caption,ai_caption,format,platform',
            ])
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(max(8, min(80, $limit)))
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptySummary();
        }

        $likes = $rows->where('sentiment', ContentFeedbackEntry::SENTIMENT_LIKE)->values();
        $dislikes = $rows->where('sentiment', ContentFeedbackEntry::SENTIMENT_DISLIKE)->values();

        $preferredFormats = $this->topCounts($likes->map(
            fn (ContentFeedbackEntry $entry) => Str::lower((string) ($entry->contentItem?->format ?? ''))
        ));
        $preferredPlatforms = $this->topCounts(
            $likes->flatMap(function (ContentFeedbackEntry $entry): array {
                $raw = (string) ($entry->contentItem?->platform ?? '');
                $parts = preg_split('/[\s,;|]+/', Str::lower($raw)) ?: [];

                return array_values(array_filter(array_map('trim', $parts)));
            })
        );
        $priorityCategories = $this->topCounts($dislikes->map(
            fn (ContentFeedbackEntry $entry) => $entry->resolvedCategory()
        ), 6);
        $categoryBreakdown = $this->assocCounts($dislikes->map(
            fn (ContentFeedbackEntry $entry) => $entry->resolvedCategory()
        ));
        $severityBreakdown = $this->assocCounts($dislikes->map(
            fn (ContentFeedbackEntry $entry) => $entry->resolvedSeverity()
        ));
        $positiveSignals = $this->buildPositiveSignals($likes);
        $hardAvoidRules = $this->buildHardAvoidRules($dislikes);
        $likedExamples = $this->buildLikedExamples($likes);
        $recentObjections = $this->buildRecentObjections($dislikes);
        $scoreAverages = $this->buildScoreAverages($rows);
        $reusableSignals = $this->buildReusableSignals(
            $positiveSignals,
            $hardAvoidRules,
            $priorityCategories,
            $severityBreakdown
        );

        return [
            'total_count' => $rows->count(),
            'likes_count' => $likes->count(),
            'dislikes_count' => $dislikes->count(),
            'preferred_formats' => $preferredFormats,
            'preferred_platforms' => $preferredPlatforms,
            'priority_categories' => $priorityCategories,
            'category_breakdown' => $categoryBreakdown,
            'severity_breakdown' => $severityBreakdown,
            'score_averages' => $scoreAverages,
            'positive_signals' => $positiveSignals,
            'hard_avoid_rules' => $hardAvoidRules,
            'liked_examples' => $likedExamples,
            'recent_objections' => $recentObjections,
            'reusable_signals' => $reusableSignals,
            'retrieval_hints' => $this->buildRetrievalHints(
                $positiveSignals,
                $hardAvoidRules,
                $priorityCategories,
                $severityBreakdown,
                $dislikes
            ),
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total_count' => 0,
            'likes_count' => 0,
            'dislikes_count' => 0,
            'preferred_formats' => [],
            'preferred_platforms' => [],
            'priority_categories' => [],
            'category_breakdown' => [],
            'severity_breakdown' => [],
            'score_averages' => [],
            'positive_signals' => [],
            'hard_avoid_rules' => [],
            'liked_examples' => [],
            'recent_objections' => [],
            'reusable_signals' => [],
            'retrieval_hints' => [
                'positive' => [],
                'negative' => [],
                'blocking_categories' => [],
                'high_severity_categories' => [],
                'priority_categories' => [],
            ],
        ];
    }

    /**
     * @param  Collection<int, ContentFeedbackEntry>  $likes
     * @return array<int, string>
     */
    private function buildPositiveSignals(Collection $likes): array
    {
        $signals = [];

        foreach ($likes as $entry) {
            $reason = $this->normalizeReason((string) ($entry->reason ?? ''));
            if ($reason !== '') {
                $signals[] = $reason;
            }

            $format = trim((string) ($entry->contentItem?->format ?? ''));
            if ($format !== '') {
                $signals[] = 'Formato gradito: ' . Str::lower($format);
            }

            $title = trim((string) ($entry->contentItem?->title ?? ''));
            if ($title !== '') {
                $signals[] = 'Titolo apprezzato: ' . Str::limit($title, 90, '');
            }
        }

        return array_values(array_unique(array_slice(array_values(array_filter($signals)), 0, 12)));
    }

    /**
     * @param  Collection<int, ContentFeedbackEntry>  $dislikes
     * @return array<int, string>
     */
    private function buildHardAvoidRules(Collection $dislikes): array
    {
        $rules = [];

        foreach ($dislikes as $entry) {
            $reason = $this->normalizeReason((string) ($entry->reason ?? ''));
            if ($reason === '') {
                continue;
            }

            $label = $entry->resolvedCategoryLabel() ?? 'Altro';
            $severityLabel = $entry->resolvedSeverityLabel();
            $prefix = $severityLabel ? $label . ' [' . $severityLabel . ']' : $label;
            $rules[] = $prefix . ': ' . $reason;
        }

        return array_values(array_unique(array_slice(array_values(array_filter($rules)), 0, 12)));
    }

    /**
     * @param  Collection<int, ContentFeedbackEntry>  $likes
     * @return array<int, array<string, mixed>>
     */
    private function buildLikedExamples(Collection $likes): array
    {
        return $likes
            ->take(6)
            ->map(function (ContentFeedbackEntry $entry): array {
                $item = $entry->contentItem;

                return [
                    'content_item_id' => (int) $entry->content_item_id,
                    'title' => Str::limit((string) ($item?->title ?? 'Contenuto'), 90, ''),
                    'format' => (string) ($item?->format ?? ''),
                    'platform' => (string) ($item?->platform ?? ''),
                    'reason' => $this->normalizeReason((string) ($entry->reason ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ContentFeedbackEntry>  $dislikes
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentObjections(Collection $dislikes): array
    {
        return $dislikes
            ->take(8)
            ->map(function (ContentFeedbackEntry $entry): array {
                $legacyCategory = Str::lower(trim((string) ($entry->category ?? '')));
                $normalizedCategory = $entry->resolvedCategory();
                $severity = $entry->resolvedSeverity();

                return [
                    'content_item_id' => (int) $entry->content_item_id,
                    'category' => $legacyCategory,
                    'category_label' => ContentFeedbackEntry::labelForCategory($legacyCategory),
                    'normalized_category' => $normalizedCategory,
                    'normalized_category_label' => $entry->resolvedCategoryLabel() ?? 'Altro',
                    'severity' => $severity,
                    'severity_label' => $entry->resolvedSeverityLabel(),
                    'scope' => (string) ($entry->scope ?? ContentFeedbackEntry::SCOPE_FULL),
                    'reason' => $this->normalizeReason((string) ($entry->reason ?? '')),
                    'action' => (string) ($entry->action ?? ContentFeedbackEntry::ACTION_RECORD_ONLY),
                    'scores' => $entry->scorePayload(),
                    'created_at' => optional($entry->created_at)->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $positiveSignals
     * @param  array<int, string>  $hardAvoidRules
     * @param  array<int, string>  $priorityCategories
     * @param  array<string, int>  $severityBreakdown
     * @return array<int, string>
     */
    private function buildReusableSignals(
        array $positiveSignals,
        array $hardAvoidRules,
        array $priorityCategories,
        array $severityBreakdown
    ): array {
        $signals = array_merge(
            array_slice($positiveSignals, 0, 4),
            array_slice($hardAvoidRules, 0, 6),
            array_map(
                fn (string $category): string => 'Categoria sensibile: ' . (ContentFeedbackEntry::labelForCategory($category) ?? $category),
                array_slice($priorityCategories, 0, 3)
            )
        );

        if (($severityBreakdown[ContentFeedbackEntry::SEVERITY_BLOCKING] ?? 0) > 0) {
            $signals[] = 'Sono presenti feedback bloccanti recenti: non ripetere gli errori piu gravi.';
        }

        return array_values(array_unique(array_slice(array_values(array_filter($signals)), 0, 14)));
    }

    /**
     * @param  array<int, string>  $positiveSignals
     * @param  array<int, string>  $hardAvoidRules
     * @param  array<int, string>  $priorityCategories
     * @param  array<string, int>  $severityBreakdown
     * @param  Collection<int, ContentFeedbackEntry>  $dislikes
     * @return array<string, mixed>
     */
    private function buildRetrievalHints(
        array $positiveSignals,
        array $hardAvoidRules,
        array $priorityCategories,
        array $severityBreakdown,
        Collection $dislikes
    ): array {
        $blockingCategories = $dislikes
            ->filter(fn (ContentFeedbackEntry $entry) => $entry->resolvedSeverity() === ContentFeedbackEntry::SEVERITY_BLOCKING)
            ->map(fn (ContentFeedbackEntry $entry) => $entry->resolvedCategory())
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        $highSeverityCategories = $dislikes
            ->filter(fn (ContentFeedbackEntry $entry) => in_array(
                $entry->resolvedSeverity(),
                [ContentFeedbackEntry::SEVERITY_HIGH, ContentFeedbackEntry::SEVERITY_BLOCKING],
                true
            ))
            ->map(fn (ContentFeedbackEntry $entry) => $entry->resolvedCategory())
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'positive' => array_slice($positiveSignals, 0, 8),
            'negative' => array_slice($hardAvoidRules, 0, 8),
            'blocking_categories' => $blockingCategories,
            'high_severity_categories' => $highSeverityCategories,
            'priority_categories' => array_slice($priorityCategories, 0, 6),
            'has_blocking_feedback' => ($severityBreakdown[ContentFeedbackEntry::SEVERITY_BLOCKING] ?? 0) > 0,
        ];
    }

    /**
     * @param  Collection<int, ContentFeedbackEntry>  $rows
     * @return array<string, float>
     */
    private function buildScoreAverages(Collection $rows): array
    {
        $totals = [];
        $counts = [];

        foreach ($rows as $row) {
            foreach ($row->scorePayload() as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0.0) + (float) $value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $averages = [];
        foreach ($totals as $key => $total) {
            $count = max(1, (int) ($counts[$key] ?? 1));
            $averages[$key] = round($total / $count, 2);
        }

        ksort($averages);

        return $averages;
    }

    /**
     * @param  Collection<int, string>  $values
     * @return array<int, string>
     */
    private function topCounts(Collection $values, int $limit = 5): array
    {
        return $values
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $values
     * @return array<string, int>
     */
    private function assocCounts(Collection $values): array
    {
        return $values
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->countBy()
            ->sortDesc()
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return '';
        }

        $reason = preg_replace('/\s+/u', ' ', $reason) ?? $reason;

        return Str::limit($reason, 180, '');
    }
}
