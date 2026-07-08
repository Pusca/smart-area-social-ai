<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAiService
{
    /**
     * Normalizza base URL:
     * - accetta https://api.openai.com
     * - accetta https://api.openai.com/v1
     * e restituisce SEMPRE host senza /v1 finale.
     */
    protected function baseHost(): string
    {
        $base = (string) (config('openai.base_url') ?: 'https://api.openai.com');
        $base = rtrim(trim($base), '/');
        if (str_ends_with($base, '/v1')) {
            $base = rtrim(substr($base, 0, -3), '/');
        }
        return $base;
    }

    protected function apiKey(): string
    {
        $key = (string) (config('openai.api_key') ?: '');
        if (trim($key) === '') {
            throw new RuntimeException('Missing OPENAI_API_KEY');
        }
        return $key;
    }

    protected function url(string $path): string
    {
        // $path deve iniziare con "/v1/..."
        return $this->baseHost() . $path;
    }

    /**
     * TESTO (Responses API + structured outputs).
     * $context: ['brand' => [...], 'plan' => [...], 'item' => [...], 'topic' => [...]|null]
     * Ritorna: caption, hashtags[], cta, image_prompt, usage.
     */
    public function generateContent(array $context): array
    {
        $language = (string) (config('openai.language') ?: 'italiano');
        $platform = (string) data_get($context, 'item.platform', 'instagram');
        $format = (string) data_get($context, 'item.format', 'post');

        $rules = $this->platformRules($platform);

        $instructions =
            "Sei un social media manager e copywriter senior specializzato in piccole e medie attività.\n"
            . "Scrivi contenuti in {$language}, pronti da pubblicare, specifici per il brand e l'argomento indicati: mai generici, mai riempitivi.\n\n"
            . "Piattaforma: {$platform} (formato: {$format}).\n"
            . $rules . "\n\n"
            . "Regole generali:\n"
            . "- La caption apre con un hook forte e sviluppa UN solo argomento (quello in \"topic\", se presente).\n"
            . "- Non inventare dati, prezzi, promozioni o recapiti non presenti nel contesto.\n"
            . "- La CTA deve essere coerente con quella preferita dal brand, se indicata.\n"
            . "- Ogni hashtag inizia con # ed è senza spazi.\n"
            . "- \"image_prompt\": scrivilo in inglese, descrittivo e fotografico (soggetto, ambiente, luce, stile), coerente con brand e argomento, senza testo né loghi nell'immagine.";

        $schema = [
            'type' => 'object',
            'properties' => [
                'caption' => ['type' => 'string'],
                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'cta' => ['type' => 'string'],
                'image_prompt' => ['type' => 'string'],
            ],
            'required' => ['caption', 'hashtags', 'cta', 'image_prompt'],
            'additionalProperties' => false,
        ];

        $data = $this->responsesCall($instructions, $context, 'social_post', $schema);
        $parsed = $data['parsed'];

        return [
            'caption' => $parsed['caption'] ?? null,
            'hashtags' => $this->normalizeHashtags($parsed['hashtags'] ?? []),
            'cta' => $parsed['cta'] ?? null,
            'image_prompt' => $parsed['image_prompt'] ?? null,
            'usage' => $data['usage'],
        ];
    }

    /**
     * IDEAZIONE PIANO: genera $count argomenti distinti e complementari.
     * $context: ['brand' => [...], 'plan' => [...], 'schedule' => [...]]
     * Ritorna: ['topics' => [[title, angle, key_points[]], ...], 'usage' => [...]]
     */
    public function generatePlanTopics(array $context, int $count): array
    {
        $language = (string) (config('openai.language') ?: 'italiano');

        $instructions =
            "Sei uno stratega di contenuti social per piccole e medie attività.\n"
            . "Genera ESATTAMENTE {$count} idee di post per il piano editoriale descritto nel contesto, in {$language}.\n\n"
            . "Regole:\n"
            . "- Ogni idea tratta un argomento DIVERSO: nessuna ripetizione, nessuna variazione dello stesso concetto.\n"
            . "- Varia gli angoli: educativo, dietro le quinte, social proof, promozionale, engagement, storytelling.\n"
            . "- Massimo 1 idea promozionale ogni 4.\n"
            . "- Non inventare offerte, sconti, prezzi o eventi non presenti nel contesto.\n"
            . "- Ogni idea deve essere concreta e legata ai servizi/prodotti reali del brand nel contesto.\n"
            . "- \"title\": breve e specifico (max 80 caratteri). \"angle\": il taglio scelto. \"key_points\": 2-4 punti che il post deve toccare.";

        $schema = [
            'type' => 'object',
            'properties' => [
                'topics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'angle' => ['type' => 'string'],
                            'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['title', 'angle', 'key_points'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['topics'],
            'additionalProperties' => false,
        ];

        // Più argomenti = più token: dimensiona l'output sul numero di post
        $maxTokens = max((int) (config('openai.max_output_tokens') ?: 1200), 150 * $count);

        $data = $this->responsesCall($instructions, $context, 'plan_topics', $schema, $maxTokens);

        $topics = $data['parsed']['topics'] ?? [];
        if (!is_array($topics) || $topics === []) {
            throw new RuntimeException('Nessun topic generato per il piano');
        }

        return ['topics' => array_values($topics), 'usage' => $data['usage']];
    }

    /**
     * IMMAGINI: per gpt-image-* NON inviare response_format (errore 400).
     * b64_json arriva di default per i GPT image models.
     */
    public function generateImageBase64(string $prompt, ?string $modelOverride = null): array
    {
        $model = trim((string) ($modelOverride ?: config('openai.image_model') ?: 'gpt-image-1'));
        // Guardia: mai chiamare le Images API con un modello testo
        if ($model === '' || (!str_contains($model, 'image') && !str_starts_with($model, 'dall-e'))) {
            $model = 'gpt-image-1';
        }

        $timeout = (int) (config('openai.timeout_images') ?: 120);
        $url = $this->url('/v1/images/generations');

        try {
            $res = Http::withToken($this->apiKey())
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry(2, 500)
                ->post($url, [
                    'model' => $model,
                    'prompt' => $prompt,
                    'size' => config('openai.image_size') ?: '1024x1024',
                    // NIENTE response_format qui
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI image error ({$res->status()}) BODY=" . $res->body());
            }

            $data = $res->json();
            $b64 = trim((string) data_get($data, 'data.0.b64_json', ''));

            if ($b64 === '') {
                throw new RuntimeException('Missing data.0.b64_json in images response');
            }

            return [
                'b64' => $b64,
                'usage' => data_get($data, 'usage', []),
            ];
        } catch (Throwable $e) {
            Log::warning('OpenAiService generateImageBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            throw $e;
        }
    }

    /**
     * Chiamata Responses API con structured output (json_schema strict).
     * Ritorna ['parsed' => array, 'usage' => array].
     */
    protected function responsesCall(string $instructions, array $context, string $schemaName, array $schema, ?int $maxTokens = null): array
    {
        $model = (string) (config('openai.text_model') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);
        $maxTokens = $maxTokens ?: (int) (config('openai.max_output_tokens') ?: 1200);

        $url = $this->url('/v1/responses');

        try {
            $res = Http::withToken($this->apiKey())
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry(2, 300)
                ->post($url, [
                    'model' => $model,
                    'instructions' => $instructions,
                    'input' => "Contesto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'max_output_tokens' => $maxTokens,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => $schemaName,
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI text error ({$res->status()}) BODY=" . $res->body());
            }

            $data = $res->json();
            $text = $this->extractResponsesText($data);
            $parsed = $this->safeJsonParse($text);

            return [
                'parsed' => is_array($parsed) ? $parsed : [],
                'usage' => [
                    'model' => $model,
                    'input_tokens' => (int) data_get($data, 'usage.input_tokens', 0),
                    'output_tokens' => (int) data_get($data, 'usage.output_tokens', 0),
                ],
            ];
        } catch (Throwable $e) {
            Log::error('OpenAiService responsesCall failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'schema' => $schemaName,
            ]);
            throw $e;
        }
    }

    protected function platformRules(string $platform): string
    {
        return match ($platform) {
            'instagram' =>
                "- Caption: max 150 parole; i primi 100 caratteri devono agganciare (decidono il tap su \"altro\").\n"
                . "- Hashtag: 8-12, mix di tag ampi e di nicchia, NON dentro la caption.\n"
                . "- Emoji: sì, con moderazione e coerenti col tono.",
            'facebook' =>
                "- Caption: max 100 parole, tono conversazionale.\n"
                . "- Hashtag: massimo 3.\n"
                . "- Chiudi invitando all'interazione (domanda o invito a commentare).",
            'tiktok' =>
                "- Caption breve (max 60 parole), hook fortissimo nella prima frase.\n"
                . "- Hashtag: 4-6 pertinenti.",
            'linkedin' =>
                "- Tono professionale ma umano, max 180 parole, prima riga d'impatto.\n"
                . "- Hashtag: 3-5 professionali.\n"
                . "- Emoji: poche o nessuna.",
            default =>
                "- Caption: max 120 parole, hook nella prima riga.\n"
                . "- Hashtag: 5-10 pertinenti.",
        };
    }

    protected function normalizeHashtags(mixed $hashtags): array
    {
        if (is_string($hashtags)) {
            $hashtags = preg_split('/[\s,]+/', trim($hashtags)) ?: [];
        }
        if (!is_array($hashtags)) {
            return [];
        }

        $out = [];
        foreach ($hashtags as $tag) {
            if (!is_string($tag)) continue;
            $tag = trim($tag);
            if ($tag === '' || $tag === '#') continue;
            $tag = '#' . ltrim($tag, '#');
            $out[$tag] = true;
        }

        return array_slice(array_keys($out), 0, 15);
    }

    protected function extractResponsesText(array $response): string
    {
        $out = $response['output'] ?? [];
        if (!is_array($out)) return '';

        $chunks = [];

        foreach ($out as $item) {
            $content = $item['content'] ?? null;
            if (!is_array($content)) continue;

            foreach ($content as $c) {
                // vari formati: {type:"output_text", text:"..."} o {type:"text", text:"..."}
                $t = $c['text'] ?? null;
                if (is_string($t) && trim($t) !== '') {
                    $chunks[] = $t;
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * Parsatore JSON robusto (rimuove ```json ... ``` se presenti)
     */
    protected function safeJsonParse(string $text): mixed
    {
        $t = trim($text);

        if (str_starts_with($t, '```')) {
            $t = preg_replace('/^```[a-zA-Z]*\s*/', '', $t) ?? $t;
            $t = preg_replace('/\s*```$/', '', $t) ?? $t;
            $t = trim($t);
        }

        $decoded = json_decode($t, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;

        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($t, $start, $end - $start + 1);
            $decoded2 = json_decode($slice, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded2;
        }

        throw new RuntimeException('Risposta non JSON: ' . mb_substr($text, 0, 500));
    }
}
