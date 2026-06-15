<?php

namespace App\Services\AI;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\TenantProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class TenantFineTuningDatasetService
{
    /**
     * @return array<string, mixed>
     */
    public function previewStats(int $tenantId): array
    {
        $rows = $this->candidateItems($tenantId);
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();

        return [
            'business_name' => trim((string) ($profile?->business_name ?? '')),
            'eligible_examples' => $rows->count(),
            'minimum_examples' => (int) config('generation.fine_tuning_min_examples', 12),
            'recommended_examples' => max(20, (int) config('generation.fine_tuning_min_examples', 12) * 2),
            'published_examples' => $rows->whereNotNull('published_at')->count(),
            'liked_examples' => $rows->filter(fn (ContentItem $item) => ($item->latestFeedbackEntry?->sentiment ?? null) === ContentFeedbackEntry::SENTIMENT_LIKE)->count(),
            'platforms' => $rows
                ->flatMap(fn (ContentItem $item) => $item->platforms())
                ->filter(fn ($value) => trim((string) $value) !== '')
                ->unique()
                ->values()
                ->all(),
            'formats' => $rows
                ->map(fn (ContentItem $item) => strtolower(trim((string) ($item->format ?? ''))))
                ->filter(fn (string $value) => $value !== '')
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDataset(int $tenantId): array
    {
        $rows = $this->candidateItems($tenantId)
            ->take(max(12, (int) config('generation.fine_tuning_max_examples', 80)))
            ->values();

        $minimumExamples = max(8, (int) config('generation.fine_tuning_min_examples', 12));
        if ($rows->count() < $minimumExamples) {
            throw new RuntimeException("Fine-tuning non avviabile: servono almeno {$minimumExamples} esempi utili e attualmente il tenant ne ha {$rows->count()}.");
        }

        $validationTarget = max(0, (int) config('generation.fine_tuning_validation_examples', 6));
        $validationCount = min($validationTarget, max(0, (int) floor($rows->count() * 0.2)), max(0, $rows->count() - $minimumExamples));
        $training = $validationCount > 0 ? $rows->slice(0, $rows->count() - $validationCount)->values() : $rows;
        $validation = $validationCount > 0 ? $rows->slice(-$validationCount)->values() : collect();

        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $systemMessage = $this->buildSystemMessage($profile);
        $runToken = now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
        $dir = storage_path('app/fine-tuning/' . $tenantId . '/' . $runToken);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossibile creare la cartella locale per il fine-tuning.');
        }

        $trainingPath = $dir . '/training.jsonl';
        $validationPath = $dir . '/validation.jsonl';

        $this->writeJsonl($trainingPath, $training->map(fn (ContentItem $item) => $this->buildTrainingLine($item, $profile, $systemMessage))->all());
        if ($validation->isNotEmpty()) {
            $this->writeJsonl($validationPath, $validation->map(fn (ContentItem $item) => $this->buildTrainingLine($item, $profile, $systemMessage))->all());
        }

        return [
            'training_path' => $trainingPath,
            'validation_path' => $validation->isNotEmpty() ? $validationPath : null,
            'training_examples' => $training->count(),
            'validation_examples' => $validation->count(),
            'stats' => [
                'eligible_examples' => $rows->count(),
                'training_examples' => $training->count(),
                'validation_examples' => $validation->count(),
                'formats' => $rows->map(fn (ContentItem $item) => strtolower(trim((string) ($item->format ?? ''))))->filter()->unique()->values()->all(),
                'platforms' => $rows->flatMap(fn (ContentItem $item) => $item->platforms())->filter()->unique()->values()->all(),
            ],
        ];
    }

    /**
     * @return Collection<int, ContentItem>
     */
    private function candidateItems(int $tenantId): Collection
    {
        return ContentItem::query()
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
                $query->whereIn('status', ['approved', 'scheduled', 'published'])
                    ->orWhereNotNull('published_at')
                    ->orWhere(function ($inner) {
                        $inner->where('ai_status', 'done')
                            ->whereNotNull('ai_caption');
                    });
            })
            ->where(function ($query) {
                $query->whereNull('ai_caption')
                    ->orWhere('ai_caption', '!=', '');
            })
            ->latest('published_at')
            ->latest('scheduled_at')
            ->latest('id')
            ->limit(180)
            ->get([
                'id',
                'tenant_id',
                'title',
                'caption',
                'ai_caption',
                'ai_cta',
                'ai_image_prompt',
                'ai_video_prompt',
                'ai_hashtags',
                'hashtags',
                'format',
                'platform',
                'status',
                'pillar',
                'content_angle',
                'published_at',
                'scheduled_at',
                'ai_meta',
            ])
            ->reject(function (ContentItem $item): bool {
                $caption = trim((string) ($item->ai_caption ?: $item->caption ?: ''));
                if ($caption === '') {
                    return true;
                }

                return ($item->latestFeedbackEntry?->sentiment ?? null) === ContentFeedbackEntry::SENTIMENT_DISLIKE;
            })
            ->sortByDesc(fn (ContentItem $item) => $this->scoreCandidate($item))
            ->values();
    }

    private function scoreCandidate(ContentItem $item): float
    {
        $score = 1.0;
        if ($item->published_at) {
            $score += 3.0;
        }
        if (in_array((string) $item->status, ['approved', 'scheduled', 'published'], true)) {
            $score += 2.0;
        }
        if (($item->latestFeedbackEntry?->sentiment ?? null) === ContentFeedbackEntry::SENTIMENT_LIKE) {
            $score += 4.0;
        }
        if (filled($item->ai_cta)) {
            $score += 0.5;
        }
        if (filled($item->ai_image_prompt) || filled($item->ai_video_prompt)) {
            $score += 0.5;
        }

        return $score;
    }

    private function buildSystemMessage(?TenantProfile $profile): string
    {
        $businessName = trim((string) ($profile?->business_name ?? 'Brand'));
        $industry = trim((string) ($profile?->industry ?? ''));
        $tone = trim((string) ($profile?->default_tone ?? ''));
        $cta = trim((string) ($profile?->cta ?? ''));

        $parts = [
            "Sei la voce editoriale del brand {$businessName}.",
            'Rispondi sempre e solo con JSON valido contenente caption, cta, hashtags, image_prompt e video_prompt.',
            'Mantieni un tono concreto, credibile e adatto a contenuti social reali.',
        ];

        if ($industry !== '') {
            $parts[] = "Settore di riferimento: {$industry}.";
        }
        if ($tone !== '') {
            $parts[] = "Tono di riferimento: {$tone}.";
        }
        if ($cta !== '') {
            $parts[] = "Direzione CTA preferita: {$cta}.";
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTrainingLine(ContentItem $item, ?TenantProfile $profile, string $systemMessage): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $brief = $this->buildBrief($item);
        $assistantPayload = [
            'caption' => trim((string) ($item->ai_caption ?: $item->caption ?: '')),
            'cta' => trim((string) ($item->ai_cta ?? '')),
            'hashtags' => $this->extractHashtags($item),
            'image_prompt' => trim((string) ($item->ai_image_prompt ?? '')),
            'video_prompt' => trim((string) ($item->ai_video_prompt ?? '')),
        ];

        $userPacket = [
            'task' => 'Genera un contenuto social brand-aligned in JSON',
            'brief' => $brief,
            'format' => strtolower(trim((string) ($item->format ?? 'post'))),
            'platforms' => $item->platforms(),
            'goal' => trim((string) data_get($meta, 'plan.goal', $profile?->default_goal ?? '')),
            'tone' => trim((string) data_get($meta, 'plan.tone', $profile?->default_tone ?? '')),
            'pillar' => trim((string) ($item->pillar ?? '')),
            'angle' => trim((string) ($item->content_angle ?? '')),
            'cta_hint' => trim((string) data_get($meta, 'item_brain.cta', $profile?->cta ?? '')),
        ];

        return [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemMessage,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($userPacket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ],
                [
                    'role' => 'assistant',
                    'content' => json_encode($assistantPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ],
            ],
        ];
    }

    private function buildBrief(ContentItem $item): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $parts = array_values(array_filter([
            trim((string) ($item->title ?? '')),
            trim((string) data_get($meta, 'manual_brief', '')),
            trim((string) ($item->caption ?? '')),
            trim((string) ($item->content_angle ?? '')),
        ], fn (string $value) => $value !== ''));

        return Str::limit(implode(' | ', array_unique($parts)), 500, '');
    }

    /**
     * @return array<int, string>
     */
    private function extractHashtags(ContentItem $item): array
    {
        $hashtags = array_merge(
            is_array($item->ai_hashtags ?? null) ? $item->ai_hashtags : [],
            is_array($item->hashtags ?? null) ? $item->hashtags : []
        );

        if (empty($hashtags)) {
            preg_match_all('/#[\p{L}\p{N}_]+/u', (string) ($item->ai_caption ?: $item->caption ?: ''), $matches);
            $hashtags = $matches[0] ?? [];
        }

        return collect($hashtags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeJsonl(string $path, array $rows): void
    {
        $lines = [];
        foreach ($rows as $row) {
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json) || $json === '') {
                continue;
            }
            $lines[] = $json;
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}