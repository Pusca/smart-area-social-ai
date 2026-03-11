<?php

namespace App\Services\Feedback;

use App\Models\ContentFeedbackEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantFeedbackMemoryService
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
            return [
                'total_count' => 0,
                'likes_count' => 0,
                'dislikes_count' => 0,
                'preferred_formats' => [],
                'preferred_platforms' => [],
                'priority_categories' => [],
                'positive_signals' => [],
                'hard_avoid_rules' => [],
                'liked_examples' => [],
                'recent_objections' => [],
            ];
        }

        $likes = $rows->where('sentiment', ContentFeedbackEntry::SENTIMENT_LIKE)->values();
        $dislikes = $rows->where('sentiment', ContentFeedbackEntry::SENTIMENT_DISLIKE)->values();

        return [
            'total_count' => $rows->count(),
            'likes_count' => $likes->count(),
            'dislikes_count' => $dislikes->count(),
            'preferred_formats' => $this->topCounts($likes->map(fn (ContentFeedbackEntry $entry) => Str::lower((string) ($entry->contentItem?->format ?? '')))),
            'preferred_platforms' => $this->topCounts(
                $likes->flatMap(function (ContentFeedbackEntry $entry): array {
                    $raw = (string) ($entry->contentItem?->platform ?? '');
                    $parts = preg_split('/[\s,;|]+/', Str::lower($raw)) ?: [];
                    return array_values(array_filter(array_map('trim', $parts)));
                })
            ),
            'priority_categories' => $this->topCounts($dislikes->map(fn (ContentFeedbackEntry $entry) => Str::lower((string) ($entry->category ?? ''))), 6),
            'positive_signals' => $this->buildPositiveSignals($likes),
            'hard_avoid_rules' => $this->buildHardAvoidRules($dislikes),
            'liked_examples' => $this->buildLikedExamples($likes),
            'recent_objections' => $this->buildRecentObjections($dislikes),
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
            $category = Str::lower(trim((string) ($entry->category ?? '')));
            $reason = $this->normalizeReason((string) ($entry->reason ?? ''));

            if ($reason === '') {
                continue;
            }

            $label = ContentFeedbackEntry::CATEGORY_LABELS[$category] ?? null;
            $rules[] = $label
                ? $label . ': ' . $reason
                : $reason;
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
                $category = Str::lower(trim((string) ($entry->category ?? '')));

                return [
                    'content_item_id' => (int) $entry->content_item_id,
                    'category' => $category,
                    'category_label' => ContentFeedbackEntry::CATEGORY_LABELS[$category] ?? 'Altro',
                    'scope' => (string) ($entry->scope ?? ContentFeedbackEntry::SCOPE_FULL),
                    'reason' => $this->normalizeReason((string) ($entry->reason ?? '')),
                    'action' => (string) ($entry->action ?? ContentFeedbackEntry::ACTION_RECORD_ONLY),
                    'created_at' => optional($entry->created_at)->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
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
