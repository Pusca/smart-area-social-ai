<?php

namespace App\Services\Trends;

use App\Models\ContentItem;
use App\Services\OpenAiService;
use Illuminate\Support\Facades\Log;

class TrendContentTranslatorService extends OpenAiService
{
    /**
     * Converts raw trend signals into concrete, brand-specific editorial ideas
     * via a single lightweight GPT call.
     *
     * @param  array<int, array<string, mixed>>  $selectedSignals  Output of TenantTrendSelectorService
     * @param  array<string, mixed>  $tenantProfile
     * @return array<int, array{trend_topic:string,hook:string,angle:string,cta:string,format_note:string}>
     */
    public function translate(array $selectedSignals, array $tenantProfile, ContentItem $item): array
    {
        if (empty($selectedSignals)) {
            return [];
        }

        $brandName = (string) ($tenantProfile['business_name'] ?? $tenantProfile['name'] ?? 'il brand');
        $industry  = (string) ($tenantProfile['industry'] ?? '');
        $services  = (string) ($tenantProfile['services'] ?? '');
        $target    = (string) ($tenantProfile['target'] ?? '');
        $tone      = (string) ($tenantProfile['default_tone'] ?? 'professionale');
        $platform  = strtolower(explode(',', (string) ($item->platform ?? 'instagram'))[0]);
        $format    = strtolower(trim((string) ($item->format ?? 'post')));
        $title     = trim((string) ($item->title ?? ''));

        $signalLines = [];
        foreach ($selectedSignals as $i => $sig) {
            $hooks = implode(' / ', array_slice((array) ($sig['hook_patterns'] ?? []), 0, 2));
            $notes = implode('; ', array_slice((array) ($sig['style_notes'] ?? []), 0, 2));
            $signalLines[] = sprintf(
                '%d. TOPIC: %s | HOOK PATTERNS: %s | STYLE: %s | FRESHNESS: %.2f | BRAND FIT: %.2f',
                $i + 1,
                (string) ($sig['topic'] ?? ''),
                $hooks ?: '—',
                $notes ?: '—',
                (float) ($sig['freshness_score'] ?? 0),
                (float) ($sig['brand_fit_score'] ?? 0)
            );
        }

        $signalsBlock = implode("\n", $signalLines);
        $titleHint    = $title !== '' ? "\nArgomento del post: {$title}" : '';
        $count        = count($selectedSignals);

        $systemPrompt = <<<PROMPT
Sei un content strategist esperto. Devi tradurre segnali di trend in idee editoriali concrete per un brand specifico.
Non generi il post finale — produci solo le istruzioni creative per il copywriter AI che lo scriverà.
Rispondi ESCLUSIVAMENTE con JSON valido nel formato richiesto, nessun testo aggiuntivo.
PROMPT;

        $userPrompt = <<<PROMPT
BRAND: {$brandName}
SETTORE: {$industry}
SERVIZI: {$services}
TARGET: {$target}
TONO: {$tone}
PIATTAFORMA: {$platform}
FORMATO: {$format}{$titleHint}

SEGNALI DI TREND SELEZIONATI:
{$signalsBlock}

Per ogni segnale, genera UN'IDEA EDITORIALE CONCRETA per questo brand.
Non copiare il trend — traducilo nel linguaggio e nella prospettiva del brand.
L'idea deve essere specifica, applicabile, non generica.

Rispondi con questo JSON (esattamente {$count} idee, una per segnale):
{
  "ideas": [
    {
      "trend_topic": "nome del trend di riferimento (dal segnale)",
      "hook": "prima frase del post — fermascroll, max 15 parole",
      "angle": "angolo narrativo — cosa racconta questo post (max 30 parole)",
      "cta": "call-to-action suggerita (max 10 parole)",
      "format_note": "nota esecutiva specifica per {$format} su {$platform} (max 20 parole)"
    }
  ]
}
PROMPT;

        try {
            $model    = config('openai.text_model', 'gpt-4.1-mini');
            $response = $this->request(45)->post($this->url('/v1/chat/completions'), [
                'model'           => $model,
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature'     => 0.75,
                'max_tokens'      => 800,
            ]);

            if ($response->failed()) {
                Log::warning('TrendContentTranslatorService: GPT error', [
                    'status'    => $response->status(),
                    'tenant_id' => $item->tenant_id,
                ]);
                return [];
            }

            $content = $response->json('choices.0.message.content', '{}');
            $parsed  = json_decode($content, true);

            if (!is_array($parsed)) {
                return [];
            }

            $ideas = (array) ($parsed['ideas'] ?? []);

            return array_values(array_filter(
                array_map(fn ($idea) => is_array($idea) ? $this->normalizeIdea($idea) : null, $ideas)
            ));

        } catch (\Throwable $e) {
            Log::warning('TrendContentTranslatorService: exception', [
                'error'     => $e->getMessage(),
                'tenant_id' => $item->tenant_id,
            ]);
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $idea
     * @return array{trend_topic:string,hook:string,angle:string,cta:string,format_note:string}
     */
    private function normalizeIdea(array $idea): array
    {
        return [
            'trend_topic' => (string) ($idea['trend_topic'] ?? ''),
            'hook'        => (string) ($idea['hook'] ?? ''),
            'angle'       => (string) ($idea['angle'] ?? ''),
            'cta'         => (string) ($idea['cta'] ?? ''),
            'format_note' => (string) ($idea['format_note'] ?? ''),
        ];
    }
}
