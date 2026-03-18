<?php

namespace App\Services\AI;

use App\Models\BrandAsset;
use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\TenantProfile;
use App\Services\MemoryBuilderService;
use Illuminate\Support\Str;

class TenantContentIntelligenceService
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder
    ) {
    }

    /**
     * @param  array<int, string>  $platforms
     * @return array<string, mixed>
     */
    public function buildForGeneration(
        int $tenantId,
        string $brief = '',
        string $format = '',
        array $platforms = []
    ): array {
        $brief = trim($brief);
        $format = strtolower(trim($format));
        $platforms = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $platforms
        ))));

        $profile = TenantProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();

        $memory = $this->memoryBuilder->buildForTenant($tenantId, 40);

        $assets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->get(['kind'])
            ->groupBy(fn (BrandAsset $asset) => strtolower(trim((string) $asset->kind)));

        $examples = $this->selectExamples($tenantId, $brief, $format, $platforms);
        $negativeExamples = $this->selectNegativeExamples($tenantId, $brief, $format, $platforms);
        $feedbackSignals = $this->buildFeedbackSignals($tenantId);

        $knowledgePack = [
            'brand_basics' => [
                'business_name' => trim((string) ($profile?->business_name ?? '')),
                'industry' => trim((string) ($profile?->industry ?? '')),
                'vision' => trim((string) ($profile?->vision ?? '')),
                'mission' => trim((string) ($profile?->mission ?? '')),
                'tone' => trim((string) ($profile?->default_tone ?? '')),
                'cta' => trim((string) ($profile?->cta ?? '')),
            ],
            'asset_counts' => [
                'logos' => (int) (($assets['logo'] ?? collect())->count()),
                'images' => (int) (($assets['image'] ?? collect())->count()),
                'videos' => (int) (($assets['video'] ?? collect())->count()),
            ],
            'memory' => [
                'themes' => (array) ($memory['themes'] ?? []),
                'offers' => (array) ($memory['offers'] ?? []),
                'ctas' => (array) ($memory['ctas'] ?? []),
                'hashtags' => array_slice((array) ($memory['hashtags'] ?? []), 0, 12),
                'recent_titles' => array_slice((array) ($memory['recent_titles'] ?? []), 0, 6),
                'recent_hooks' => array_slice((array) ($memory['recent_hooks'] ?? []), 0, 6),
            ],
            'feedback' => [
                'preferred_formats' => (array) data_get($memory, 'feedback_summary.preferred_formats', []),
                'preferred_platforms' => (array) data_get($memory, 'feedback_summary.preferred_platforms', []),
                'positive_signals' => (array) ($memory['positive_signals'] ?? []),
                'hard_avoid_rules' => (array) ($memory['hard_avoid_rules'] ?? []),
                'recent_objections' => array_slice((array) data_get($memory, 'feedback_summary.recent_objections', []), 0, 6),
            ],
            'brief_focus' => [
                'brief_keywords' => $this->extractKeywords($brief),
                'requested_format' => $format,
                'requested_platforms' => $platforms,
            ],
        ];

        return [
            'knowledge_pack' => $knowledgePack,
            'examples' => $examples,
            'negative_examples' => $negativeExamples,
            'feedback_signals' => $feedbackSignals,
            'built_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<int, string>  $platforms
     * @return array<int, array<string, mixed>>
     */
    private function selectExamples(int $tenantId, string $brief, string $format, array $platforms): array
    {
        $keywords = $this->extractKeywords($brief);
        $rows = ContentItem::query()
            ->with([
                'latestFeedbackEntry' => fn ($query) => $query->select([
                    'content_feedback_entries.id',
                    'content_feedback_entries.content_item_id',
                    'content_feedback_entries.sentiment',
                    'content_feedback_entries.reason',
                    'content_feedback_entries.category',
                ]),
            ])
            ->where('tenant_id', $tenantId)
            ->where(function ($query) {
                $query->where('status', 'published')
                    ->orWhereNotNull('published_at')
                    ->orWhere(function ($inner) {
                        $inner->where('ai_status', 'done')
                            ->whereNotNull('ai_caption');
                    });
            })
            ->latest('published_at')
            ->latest('scheduled_at')
            ->latest('id')
            ->limit(60)
            ->get([
                'id',
                'title',
                'caption',
                'ai_caption',
                'ai_cta',
                'format',
                'platform',
                'pillar',
                'content_angle',
            ]);

        return $rows
            ->map(function (ContentItem $item) use ($keywords, $format, $platforms): array {
                $caption = trim((string) ($item->ai_caption ?: $item->caption ?: ''));
                $title = trim((string) ($item->title ?? ''));
                $score = $this->scoreItemForBrief($title . ' ' . $caption, $keywords);
                if ($format !== '' && strtolower(trim((string) $item->format)) === $format) {
                    $score += 2.0;
                }
                if (!empty($platforms)) {
                    $itemPlatforms = $item->platforms();
                    if (!empty(array_intersect($platforms, $itemPlatforms))) {
                        $score += 2.0;
                    }
                }
                if (($item->latestFeedbackEntry?->sentiment ?? null) === ContentFeedbackEntry::SENTIMENT_LIKE) {
                    $score += 3.0;
                }

                return [
                    'content_item_id' => (int) $item->id,
                    'title' => Str::limit($title, 90, ''),
                    'caption' => Str::limit($caption, 220, ''),
                    'cta' => Str::limit(trim((string) ($item->ai_cta ?? '')), 90, ''),
                    'format' => strtolower(trim((string) $item->format)),
                    'platform' => trim((string) $item->platform),
                    'pillar' => trim((string) ($item->pillar ?? '')),
                    'angle' => Str::limit(trim((string) ($item->content_angle ?? '')), 120, ''),
                    'feedback' => [
                        'sentiment' => (string) ($item->latestFeedbackEntry?->sentiment ?? ''),
                        'reason' => Str::limit(trim((string) ($item->latestFeedbackEntry?->reason ?? '')), 140, ''),
                        'category' => (string) ($item->latestFeedbackEntry?->category ?? ''),
                    ],
                    'score' => round($score, 3),
                ];
            })
            ->sortByDesc('score')
            ->take((int) (config('generation.knowledge_pack_examples_limit') ?: 5))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $platforms
     * @return array<int, array<string, mixed>>
     */
    private function selectNegativeExamples(int $tenantId, string $brief, string $format, array $platforms): array
    {
        $keywords = $this->extractKeywords($brief);

        $rows = ContentFeedbackEntry::query()
            ->with([
                'contentItem:id,title,caption,ai_caption,format,platform',
            ])
            ->where('tenant_id', $tenantId)
            ->where('sentiment', ContentFeedbackEntry::SENTIMENT_DISLIKE)
            ->latest('id')
            ->limit(40)
            ->get();

        return $rows
            ->map(function (ContentFeedbackEntry $entry) use ($keywords, $format, $platforms): array {
                $item = $entry->contentItem;
                $caption = trim((string) ($item?->ai_caption ?: $item?->caption ?: ''));
                $title = trim((string) ($item?->title ?? ''));
                $score = $this->scoreItemForBrief($title . ' ' . $caption . ' ' . (string) $entry->reason, $keywords);
                if ($format !== '' && strtolower(trim((string) ($item?->format ?? ''))) === $format) {
                    $score += 1.5;
                }
                if (!empty($platforms) && $item) {
                    $itemPlatforms = $item->platforms();
                    if (!empty(array_intersect($platforms, $itemPlatforms))) {
                        $score += 1.5;
                    }
                }

                return [
                    'content_item_id' => (int) ($entry->content_item_id ?? 0),
                    'title' => Str::limit($title, 90, ''),
                    'caption' => Str::limit($caption, 180, ''),
                    'category' => trim((string) ($entry->category ?? '')),
                    'reason' => Str::limit(trim((string) ($entry->reason ?? '')), 160, ''),
                    'scope' => trim((string) ($entry->scope ?? '')),
                    'score' => round($score, 3),
                ];
            })
            ->sortByDesc('score')
            ->take((int) (config('generation.knowledge_pack_negative_examples_limit') ?: 4))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function buildFeedbackSignals(int $tenantId): array
    {
        $rows = ContentFeedbackEntry::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(20)
            ->get(['sentiment', 'category', 'reason']);

        $signals = [];
        foreach ($rows as $row) {
            $reason = trim((string) ($row->reason ?? ''));
            if ($reason === '') {
                continue;
            }
            $category = trim((string) ($row->category ?? ''));
            $prefix = $row->sentiment === ContentFeedbackEntry::SENTIMENT_LIKE ? 'preferisci' : 'evita';
            $signals[] = trim($prefix . ' ' . ($category !== '' ? '[' . $category . '] ' : '') . $reason);
        }

        return array_values(array_unique(array_slice($signals, 0, 10)));
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function scoreItemForBrief(string $haystack, array $keywords): float
    {
        if (empty($keywords)) {
            return 1.0;
        }

        $haystack = Str::lower($haystack);
        $score = 0.0;
        foreach ($keywords as $keyword) {
            if (Str::contains($haystack, $keyword)) {
                $score += 1.2;
            }
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function extractKeywords(string $text): array
    {
        $text = Str::lower($this->normalizeUtf8($text));
        $text = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $text) ?? '';
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $stopWords = [
            'questo', 'quella', 'quello', 'della', 'delle', 'degli', 'dello', 'nelle', 'nella', 'nello',
            'con', 'per', 'una', 'uno', 'del', 'dei', 'dai', 'agli', 'alla', 'alle', 'sul', 'sui',
            'post', 'reel', 'brand', 'social', 'cliente', 'clienti', 'contenuto', 'contenuti',
        ];

        $lookup = array_fill_keys($stopWords, true);
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 4) {
                continue;
            }
            if (isset($lookup[$token])) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique(array_slice($out, 0, 12)));
    }

    private function normalizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($sanitized)) {
            $text = $sanitized;
        }

        return $text;
    }
}

