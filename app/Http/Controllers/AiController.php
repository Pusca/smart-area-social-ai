<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class AiController extends Controller
{
    public function index(): View
    {
        return view('ai');
    }

    /**
     * Route: ai.generate (POST /ai/generate)
     *
     * Supporta due modalita:
     * - coda per content_item_ids (retrocompatibilita)
     * - generazione "AI Lab" diretta (topic/tone/platform/goal/lang)
     */
    public function generate(Request $request, OpenAiService $openAiService): RedirectResponse|JsonResponse
    {
        if ($request->filled('content_item_ids')) {
            return $this->queueContentItems($request);
        }

        $validator = Validator::make($request->all(), [
            'topic' => ['required', 'string', 'min:3', 'max:180'],
            'platform' => ['required', 'in:instagram,facebook,tiktok'],
            'tone' => ['required', 'in:professionale,amichevole,ironico,tecnico,commerciale'],
            'goal' => ['required', 'in:lead,brand,engagement,vendita'],
            'lang' => ['required', 'in:it,en'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'Parametri non validi.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        try {
            $generated = $openAiService->generateContent($this->buildContext($payload));

            return response()->json([
                'ok' => true,
                'fallback' => false,
                'data' => $this->normalizeOutput($payload, $generated, false),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => true,
                'fallback' => true,
                'data' => $this->normalizeOutput($payload, [], true),
            ]);
        }
    }

    private function queueContentItems(Request $request): RedirectResponse|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content_item_ids' => ['required', 'array', 'min:1'],
            'content_item_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Parametri non validi.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        $ids = $validator->validated()['content_item_ids'];
        $items = ContentItem::whereIn('id', $ids)->get();

        foreach ($items as $item) {
            $item->ai_status = 'queued';
            $item->ai_error = null;
            $item->save();

            GenerateAiForContentItem::dispatch($item->id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'queued' => $items->count(),
                'message' => 'Generazione AI messa in coda.',
            ]);
        }

        return back()->with('status', 'Generazione AI messa in coda.');
    }

    private function buildContext(array $payload): array
    {
        return [
            'platform' => $payload['platform'],
            'goal' => $payload['goal'],
            'language' => $payload['lang'],
            'item_brain' => [
                'angle' => $payload['topic'],
                'objective' => $payload['goal'],
                'tone' => $payload['tone'],
                'cta' => $this->defaultCtaForGoal($payload['goal']),
                'uniqueness_key' => sha1($payload['topic'] . '|' . $payload['platform'] . '|' . $payload['tone'] . '|' . now()->timestamp),
            ],
            'tenant_profile' => [
                'business_name' => optional(auth()->user())->name,
            ],
            'lab_mode' => true,
        ];
    }

    private function normalizeOutput(array $payload, array $generated, bool $fallback): array
    {
        $topic = trim((string) ($payload['topic'] ?? ''));
        $caption = trim((string) ($generated['caption'] ?? ''));
        $cta = trim((string) ($generated['cta'] ?? ''));
        $hashtags = $this->normalizeHashtags($generated['hashtags'] ?? []);

        if ($caption === '') {
            $caption = $this->fallbackCaption($payload);
        }

        if ($cta === '') {
            $cta = $this->defaultCtaForGoal($payload['goal']);
        }

        if (empty($hashtags)) {
            $hashtags = $this->fallbackHashtags($topic);
        }

        $hook = $this->buildHook($topic, $caption);
        $notes = $fallback
            ? 'Output in fallback locale: il servizio AI non era raggiungibile in questo momento.'
            : 'Bozza pronta: verifica tono e dettagli prima della pubblicazione.';

        return [
            'hook' => $hook,
            'caption' => $caption,
            'hashtags' => $hashtags,
            'cta' => $cta,
            'notes' => $notes,
        ];
    }

    private function buildHook(string $topic, string $caption): string
    {
        $topic = trim($topic);

        if ($topic !== '') {
            return 'Stop allo scroll: ' . mb_substr($topic, 0, 68) . (mb_strlen($topic) > 68 ? '...' : '');
        }

        $firstSentence = preg_split('/[.!?]\s+/', trim($caption)) ?: [];
        $candidate = trim((string) ($firstSentence[0] ?? ''));

        if ($candidate === '') {
            return 'Nuova idea pronta per il tuo prossimo post.';
        }

        return mb_substr($candidate, 0, 80) . (mb_strlen($candidate) > 80 ? '...' : '');
    }

    private function fallbackCaption(array $payload): string
    {
        $topic = trim((string) ($payload['topic'] ?? 'questo tema'));
        $platform = strtoupper((string) ($payload['platform'] ?? 'social'));
        $tone = (string) ($payload['tone'] ?? 'professionale');

        return "{$platform}: {$topic}.\n\n"
            . "Approccio {$tone}: porta un esempio pratico, un risultato misurabile e una call to action chiara.";
    }

    private function fallbackHashtags(string $topic): array
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '', strtolower($topic)) ?: 'socialmedia';

        return [
            '#socialmedia',
            '#marketingdigitale',
            '#contentstrategy',
            '#' . mb_substr($slug, 0, 24),
        ];
    }

    private function normalizeHashtags(mixed $hashtags): array
    {
        if (is_string($hashtags)) {
            $hashtags = preg_split('/[\s,]+/', trim($hashtags)) ?: [];
        }

        if (!is_array($hashtags)) {
            return [];
        }

        $normalized = [];

        foreach ($hashtags as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }

            if (!str_starts_with($tag, '#')) {
                $tag = '#' . ltrim($tag, '#');
            }

            $normalized[] = mb_substr($tag, 0, 40);
        }

        return array_values(array_unique($normalized));
    }

    private function defaultCtaForGoal(string $goal): string
    {
        return match ($goal) {
            'lead' => 'Scrivimi in DM per ricevere una proposta dedicata.',
            'brand' => 'Seguici per altri contenuti utili e concreti.',
            'engagement' => 'Lascia un commento con la tua esperienza.',
            'vendita' => 'Contattaci ora per attivare il servizio.',
            default => 'Scrivici per approfondire.',
        };
    }
}
