<?php

namespace App\Services\AI;

use App\Models\BrandAsset;
use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\TenantProfile;
use App\Services\MemoryBuilderService;
use Illuminate\Support\Collection;
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
        $assetCounts = $this->loadAssetCounts($tenantId);
        $assetRows = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->limit((int) config('generation.knowledge_pack_asset_rows_limit', 48))
            ->get(['id', 'kind', 'original_name', 'mime', 'meta']);
        $assetLibrary = $this->buildAssetLibrary($assetRows);

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
                'logos' => (int) ($assetCounts['logo'] ?? 0),
                'images' => (int) ($assetCounts['image'] ?? 0),
                'videos' => (int) ($assetCounts['video'] ?? 0),
                'audios' => (int) ($assetCounts['audio'] ?? 0),
                'documents' => (int) ($assetCounts['document'] ?? 0),
                'texts' => (int) ($assetCounts['text'] ?? 0),
                'links' => (int) ($assetCounts['link'] ?? 0),
            ],
            'asset_library' => $assetLibrary,
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
     * @return array<string, int>
     */
    private function loadAssetCounts(int $tenantId): array
    {
        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->get(['kind'])
            ->countBy(fn (BrandAsset $asset) => strtolower(trim((string) $asset->kind)))
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int, BrandAsset>  $assets
     * @return array<string, mixed>
     */
    private function buildAssetLibrary(Collection $assets): array
    {
        $perKindLimit = max(1, (int) config('generation.knowledge_pack_asset_items_per_kind_limit', 6));
        $signalLimit = max(1, (int) config('generation.knowledge_pack_asset_signal_limit', 10));

        $grouped = $assets
            ->groupBy(fn (BrandAsset $asset) => strtolower(trim((string) $asset->kind)));

        $library = [
            'logos' => $this->summarizeAssetGroup($grouped->get('logo', collect()), $perKindLimit),
            'images' => $this->summarizeAssetGroup($grouped->get('image', collect()), $perKindLimit),
            'videos' => $this->summarizeAssetGroup($grouped->get('video', collect()), $perKindLimit),
            'audios' => $this->summarizeAssetGroup($grouped->get('audio', collect()), $perKindLimit),
            'documents' => $this->summarizeAssetGroup($grouped->get('document', collect()), $perKindLimit),
            'texts' => $this->summarizeAssetGroup($grouped->get('text', collect()), $perKindLimit),
            'links' => $this->summarizeAssetGroup($grouped->get('link', collect()), $perKindLimit),
        ];

        $signals = $assets
            ->map(fn (BrandAsset $asset) => $this->buildAssetSignal($asset))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->take($signalLimit)
            ->values()
            ->all();

        $notes = $assets
            ->map(fn (BrandAsset $asset) => trim((string) data_get(is_array($asset->meta) ? $asset->meta : [], 'grounding_notes', '')))
            ->filter(fn (string $note) => $note !== '')
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $knowledgeSources = collect(array_merge(
            $library['documents'],
            $library['texts'],
            $library['links']
        ))
            ->take($signalLimit)
            ->values()
            ->all();

        return $library + [
            'knowledge_sources' => $knowledgeSources,
            'identity_signals' => $signals,
            'upload_notes' => $notes,
        ];
    }

    /**
     * @param  Collection<int, BrandAsset>  $assets
     * @return array<int, array<string, mixed>>
     */
    private function summarizeAssetGroup(Collection $assets, int $limit): array
    {
        return $assets
            ->take($limit)
            ->map(fn (BrandAsset $asset) => $this->summarizeAsset($asset))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeAsset(BrandAsset $asset): array
    {
        $meta = is_array($asset->meta) ? $asset->meta : [];
        $label = trim((string) ($asset->original_name ?: data_get($meta, 'variable_name', 'asset-' . $asset->id)));
        $source = trim((string) data_get($meta, 'source', 'brand_center'));
        $notes = trim((string) data_get($meta, 'grounding_notes', ''));
        $variableName = trim((string) data_get($meta, 'variable_name', ''));
        $variableKind = trim((string) (data_get($meta, 'variable_kind', '') ?: data_get($meta, 'identity_kind', '')));
        $slot = trim((string) data_get($meta, 'slot', ''));
        $slotLabel = trim((string) data_get($meta, 'slot_label', ''));
        $sourceLabel = trim((string) (data_get($meta, 'source_label', '') ?: data_get($meta, 'text_title', '')));
        $sourceUrl = trim((string) data_get($meta, 'source_url', ''));
        $contentOrigin = trim((string) data_get($meta, 'content_origin', ''));
        $knowledgeText = trim((string) (data_get($meta, 'text_excerpt', '') ?: data_get($meta, 'knowledge_text', '')));
        $linkedVariables = collect((array) data_get($meta, 'linked_variable_slugs', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        $tags = array_values(array_unique(array_slice(array_filter(array_merge(
            $this->extractKeywords($label),
            $this->extractKeywords($notes),
            $this->extractKeywords($variableName),
            $this->extractKeywords($slotLabel),
            $this->extractKeywords($sourceLabel),
            $this->extractKeywords(Str::limit($knowledgeText, 180, ''))
        )), 0, 8)));

        $hintParts = array_values(array_filter([
            $variableName !== '' ? 'identita ' . $variableName : '',
            $slotLabel !== '' ? 'slot ' . $slotLabel : '',
            $sourceLabel !== '' ? 'titolo ' . $sourceLabel : '',
            $notes !== '' ? $notes : '',
            $knowledgeText !== '' ? Str::limit($knowledgeText, 120, '') : '',
            $source !== '' ? 'source ' . $source : '',
        ]));

        return [
            'asset_id' => (int) $asset->id,
            'kind' => strtolower(trim((string) $asset->kind)),
            'label' => Str::limit($this->normalizeUtf8($label), 80, ''),
            'mime' => trim((string) ($asset->mime ?? '')),
            'source' => $source,
            'variable_name' => $variableName,
            'variable_kind' => $variableKind,
            'slot' => $slot,
            'slot_label' => $slotLabel,
            'notes' => Str::limit($this->normalizeUtf8($notes), 180, ''),
            'content_origin' => $contentOrigin,
            'source_label' => Str::limit($this->normalizeUtf8($sourceLabel), 120, ''),
            'source_url' => Str::limit($this->normalizeUtf8($sourceUrl), 220, ''),
            'text_excerpt' => Str::limit($this->normalizeUtf8($knowledgeText), 240, ''),
            'training_priority' => trim((string) data_get($meta, 'training_priority', '')),
            'is_identity_anchor' => (bool) data_get($meta, 'is_canonical_for_identity', false),
            'linked_variables' => $linkedVariables,
            'tags' => $tags,
            'grounding_hint' => Str::limit($this->normalizeUtf8(implode(' | ', $hintParts)), 180, ''),
        ];
    }

    private function buildAssetSignal(BrandAsset $asset): string
    {
        $meta = is_array($asset->meta) ? $asset->meta : [];
        $kind = strtolower(trim((string) $asset->kind));
        $kindLabel = match ($kind) {
            'logo' => 'logo',
            'image' => 'foto',
            'video' => 'video',
            'audio' => 'voce/audio',
            'document' => 'documento',
            'text' => 'nota',
            'link' => 'link',
            default => 'asset',
        };

        $parts = [
            trim((string) data_get($meta, 'variable_name', '')),
            trim((string) data_get($meta, 'slot_label', '')),
            trim((string) data_get($meta, 'source_label', '')),
            trim((string) data_get($meta, 'grounding_notes', '')),
            trim((string) data_get($meta, 'text_excerpt', data_get($meta, 'knowledge_text', ''))),
        ];

        $parts = array_values(array_filter(array_map(
            fn ($value) => Str::limit($this->normalizeUtf8((string) $value), 120, ''),
            $parts
        ), fn (string $value) => $value !== ''));

        if (empty($parts)) {
            $label = trim((string) ($asset->original_name ?? ''));
            if ($label !== '') {
                $parts[] = Str::limit($this->normalizeUtf8($label), 120, '');
            }
        }

        if (empty($parts)) {
            return '';
        }

        return trim($kindLabel . ': ' . implode(' - ', $parts));
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
        $normalized = Str::lower($this->normalizeUtf8($text));
        $text = @preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        if (!is_string($text)) {
            $text = preg_replace('/[^A-Za-z0-9\s]/', ' ', $normalized) ?? '';
        }
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

        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($sanitized) && $sanitized !== '') {
            $text = $sanitized;
        }

        return trim($text);
    }
}
