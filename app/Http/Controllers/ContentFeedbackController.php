<?php

namespace App\Http\Controllers;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Services\MemoryBuilderService;
use App\Support\GenerationExecution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'sentiment' => 'required|string|in:like,dislike',
            'category' => 'nullable|string|max:80',
            'reason' => 'nullable|string|max:2000',
            'action' => 'nullable|string|in:record_only,regenerate',
        ]);

        $sentiment = (string) $data['sentiment'];
        $category = Str::lower(trim((string) ($data['category'] ?? '')));
        $reason = trim((string) ($data['reason'] ?? ''));
        $action = (string) ($data['action'] ?? ContentFeedbackEntry::ACTION_RECORD_ONLY);

        if ($sentiment === ContentFeedbackEntry::SENTIMENT_DISLIKE && $reason === '') {
            return back()
                ->withErrors(['reason' => 'Quando un contenuto non piace, indica in breve cosa non va.'])
                ->withInput();
        }

        if ($sentiment === ContentFeedbackEntry::SENTIMENT_LIKE) {
            $category = '';
            $action = ContentFeedbackEntry::ACTION_RECORD_ONLY;
        }

        $scope = $this->resolveScope($category);

        $entry = ContentFeedbackEntry::query()->create([
            'tenant_id' => (int) $contentItem->tenant_id,
            'content_item_id' => (int) $contentItem->id,
            'user_id' => (int) $request->user()->id,
            'sentiment' => $sentiment,
            'category' => $category !== '' ? $category : null,
            'scope' => $scope,
            'reason' => $reason !== '' ? $reason : null,
            'action' => $action,
            'meta' => [
                'format' => (string) ($contentItem->format ?? ''),
                'platform' => (string) ($contentItem->platform ?? ''),
                'ai_status' => (string) ($contentItem->ai_status ?? ''),
                'image_provider' => (string) data_get($contentItem->ai_meta, 'image_provider', ''),
                'video_provider' => (string) data_get($contentItem->ai_meta, 'video_provider', ''),
                'ai_generated_at' => optional($contentItem->ai_generated_at)->toDateTimeString(),
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
        return [
            'feedback_id' => (int) $entry->id,
            'sentiment' => (string) $entry->sentiment,
            'category' => (string) ($entry->category ?? ''),
            'category_label' => ContentFeedbackEntry::CATEGORY_LABELS[(string) ($entry->category ?? '')] ?? null,
            'scope' => (string) ($entry->scope ?? ContentFeedbackEntry::SCOPE_FULL),
            'reason' => (string) ($entry->reason ?? ''),
            'action' => (string) ($entry->action ?? ContentFeedbackEntry::ACTION_RECORD_ONLY),
            'created_at' => optional($entry->created_at)->toDateTimeString(),
        ];
    }

    private function resolveScope(string $category): string
    {
        return match ($category) {
            'realism', 'visual_composition', 'location_integrity' => ContentFeedbackEntry::SCOPE_VISUAL_FIRST,
            'tone_of_voice', 'caption_copy', 'call_to_action', 'offer_focus', 'platform_fit' => ContentFeedbackEntry::SCOPE_COPY_FIRST,
            default => ContentFeedbackEntry::SCOPE_FULL,
        };
    }

    private function buildInstruction(ContentFeedbackEntry $entry): string
    {
        $category = trim((string) ($entry->category ?? ''));
        $reason = trim((string) ($entry->reason ?? ''));
        $label = ContentFeedbackEntry::CATEGORY_LABELS[$category] ?? 'Correzione richiesta';

        return trim($label . ': ' . $reason);
    }
}
