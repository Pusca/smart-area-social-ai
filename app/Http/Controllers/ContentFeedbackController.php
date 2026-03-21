<?php

namespace App\Http\Controllers;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Services\MemoryBuilderService;
use App\Support\GenerationExecution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContentFeedbackController extends Controller
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder
    ) {
    }

    public function store(Request $request, ContentItem $contentItem): RedirectResponse
    {
        $this->authorizeTenant($request, $contentItem);

        $data = $request->validate([
            'sentiment' => ['required', 'string', Rule::in([
                ContentFeedbackEntry::SENTIMENT_LIKE,
                ContentFeedbackEntry::SENTIMENT_DISLIKE,
            ])],
            'category' => ['nullable', 'string', 'max:80', Rule::in(ContentFeedbackEntry::allowedCategoryValues())],
            'reason' => 'nullable|string|max:2000',
            'action' => ['nullable', 'string', Rule::in([
                ContentFeedbackEntry::ACTION_RECORD_ONLY,
                ContentFeedbackEntry::ACTION_REGENERATE,
            ])],
            'severity' => ['nullable', 'string', Rule::in(ContentFeedbackEntry::allowedSeverityValues())],
            'quality_score' => 'nullable|numeric|min:1|max:5',
            'brand_fit_score' => 'nullable|numeric|min:1|max:5',
            'publishability_score' => 'nullable|numeric|min:1|max:5',
        ]);

        $sentiment = (string) $data['sentiment'];
        $category = Str::lower(trim((string) ($data['category'] ?? '')));
        $reason = trim((string) ($data['reason'] ?? ''));
        $action = (string) ($data['action'] ?? ContentFeedbackEntry::ACTION_RECORD_ONLY);
        $requestedSeverity = (string) ($data['severity'] ?? '');

        if ($sentiment === ContentFeedbackEntry::SENTIMENT_DISLIKE && $reason === '') {
            return back()
                ->withErrors(['reason' => 'Quando un contenuto non piace, indica in breve cosa non va.'])
                ->withInput();
        }

        if ($sentiment === ContentFeedbackEntry::SENTIMENT_LIKE) {
            $category = '';
            $action = ContentFeedbackEntry::ACTION_RECORD_ONLY;
            $requestedSeverity = ContentFeedbackEntry::SEVERITY_LOW;
        }

        $normalizedCategory = $sentiment === ContentFeedbackEntry::SENTIMENT_LIKE && $category === '' && $reason === ''
            ? null
            : ContentFeedbackEntry::normalizeCategory($category, $reason, '');
        $scope = $this->resolveScope($category, $normalizedCategory);
        $severity = ContentFeedbackEntry::resolveSeverity(
            $requestedSeverity,
            (string) ($normalizedCategory ?: 'other'),
            $reason,
            $action,
            $sentiment
        );
        $scores = $this->extractScores($data);

        $entry = ContentFeedbackEntry::query()->create([
            'tenant_id' => (int) $contentItem->tenant_id,
            'content_item_id' => (int) $contentItem->id,
            'user_id' => (int) $request->user()->id,
            'sentiment' => $sentiment,
            'category' => $category !== '' ? $category : null,
            'normalized_category' => $normalizedCategory,
            'scope' => $scope,
            'severity' => $severity,
            'reason' => $reason !== '' ? $reason : null,
            'action' => $action,
            'scores' => !empty($scores) ? $scores : null,
            'meta' => [
                'format' => (string) ($contentItem->format ?? ''),
                'platform' => (string) ($contentItem->platform ?? ''),
                'ai_status' => (string) ($contentItem->ai_status ?? ''),
                'image_provider' => (string) data_get($contentItem->ai_meta, 'image_provider', ''),
                'video_provider' => (string) data_get($contentItem->ai_meta, 'video_provider', ''),
                'ai_generated_at' => optional($contentItem->ai_generated_at)->toDateTimeString(),
                'structured_feedback' => [
                    'normalized_category' => $normalizedCategory,
                    'severity' => $severity,
                    'scores' => $scores,
                ],
            ],
        ]);

        $meta = is_array($contentItem->ai_meta) ? $contentItem->ai_meta : [];
        $feedbackLoop = is_array($meta['feedback_loop'] ?? null) ? $meta['feedback_loop'] : [];
        $feedbackLoop['latest_feedback'] = $this->buildFeedbackSnapshot($entry);

        $memorySummary = $this->memoryBuilder->buildForTenant((int) $contentItem->tenant_id, 40);
        $meta['memory_summary'] = $memorySummary;

        if (
            $sentiment === ContentFeedbackEntry::SENTIMENT_DISLIKE
            && $action === ContentFeedbackEntry::ACTION_REGENERATE
        ) {
            $feedbackLoop['active_request'] = array_merge(
                $this->buildFeedbackSnapshot($entry),
                [
                    'instruction' => $this->buildInstruction($entry),
                    'requested_at' => now()->toDateTimeString(),
                ]
            );
            $contentItem->ai_status = 'queued';
            $contentItem->ai_error = null;
        }

        $meta['feedback_loop'] = $feedbackLoop;
        $contentItem->ai_meta = $meta;
        $contentItem->save();

        if (
            $sentiment === ContentFeedbackEntry::SENTIMENT_DISLIKE
            && $action === ContentFeedbackEntry::ACTION_REGENERATE
        ) {
            GenerationExecution::dispatchContentItem((int) $contentItem->id);

            if (GenerationExecution::shouldShowProgressPage()) {
                return redirect()->route('posts.generating', $contentItem);
            }

            return redirect()
                ->route('posts.edit', $contentItem)
                ->with('status', GenerationExecution::shouldRunSync()
                    ? 'Feedback salvato e rigenerazione completata con le correzioni richieste.'
                    : 'Feedback salvato e rigenerazione avviata con le correzioni richieste.');
        }

        return redirect()
            ->route('posts.edit', $contentItem)
            ->with('status', $sentiment === ContentFeedbackEntry::SENTIMENT_LIKE
                ? 'Feedback positivo salvato. Lo usero per migliorare i prossimi contenuti.'
                : 'Feedback salvato. La macchina terra conto di questa obiezione nelle prossime generazioni.');
    }

    private function authorizeTenant(Request $request, ContentItem $item): void
    {
        if ((int) $item->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFeedbackSnapshot(ContentFeedbackEntry $entry): array
    {
        $legacyCategory = (string) ($entry->category ?? '');
        $normalizedCategory = (string) ($entry->normalized_category ?: $entry->resolvedCategory());

        if (
            $entry->sentiment === ContentFeedbackEntry::SENTIMENT_LIKE
            && $legacyCategory === ''
            && trim((string) ($entry->reason ?? '')) === ''
        ) {
            $normalizedCategory = '';
        }

        $severity = (string) ($entry->severity ?: $entry->resolvedSeverity());

        return [
            'feedback_id' => (int) $entry->id,
            'sentiment' => (string) $entry->sentiment,
            'category' => $legacyCategory,
            'category_label' => ContentFeedbackEntry::labelForCategory($legacyCategory),
            'normalized_category' => $normalizedCategory,
            'normalized_category_label' => ContentFeedbackEntry::labelForCategory($normalizedCategory),
            'scope' => (string) ($entry->scope ?? ContentFeedbackEntry::SCOPE_FULL),
            'severity' => $severity,
            'severity_label' => ContentFeedbackEntry::labelForSeverity($severity),
            'reason' => (string) ($entry->reason ?? ''),
            'action' => (string) ($entry->action ?? ContentFeedbackEntry::ACTION_RECORD_ONLY),
            'scores' => $entry->scorePayload(),
            'created_at' => optional($entry->created_at)->toDateTimeString(),
        ];
    }

    private function resolveScope(string $category, ?string $normalizedCategory = null): string
    {
        $normalizedCategory = Str::lower(trim((string) $normalizedCategory));
        $category = Str::lower(trim($category));

        $visualCategories = [
            'realism',
            'visual_composition',
            'location_integrity',
            'person_not_consistent',
            'location_not_consistent',
            'product_deformed',
            'low_quality_visual',
        ];
        $copyCategories = [
            'tone_of_voice',
            'caption_copy',
            'call_to_action',
            'offer_focus',
            'platform_fit',
            'too_generic',
            'too_salesy',
            'wrong_cta',
            'audio_unatural',
        ];

        if (in_array($normalizedCategory, $visualCategories, true) || in_array($category, $visualCategories, true)) {
            return ContentFeedbackEntry::SCOPE_VISUAL_FIRST;
        }

        if (in_array($normalizedCategory, $copyCategories, true) || in_array($category, $copyCategories, true)) {
            return ContentFeedbackEntry::SCOPE_COPY_FIRST;
        }

        return ContentFeedbackEntry::SCOPE_FULL;
    }

    private function buildInstruction(ContentFeedbackEntry $entry): string
    {
        $reason = trim((string) ($entry->reason ?? ''));
        $label = $entry->resolvedCategoryLabel()
            ?? ContentFeedbackEntry::labelForCategory((string) ($entry->category ?? ''))
            ?? 'Correzione richiesta';

        return trim($label . ': ' . $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|float>
     */
    private function extractScores(array $data): array
    {
        $mapping = [
            'quality_score' => 'quality_score',
            'brand_fit_score' => 'brand_fit_score',
            'publishability_score' => 'publishability_score',
        ];

        $scores = [];
        foreach ($mapping as $field => $key) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }

            $numeric = (float) $data[$field];
            $scores[$key] = floor($numeric) === $numeric ? (int) $numeric : round($numeric, 2);
        }

        return $scores;
    }
}
