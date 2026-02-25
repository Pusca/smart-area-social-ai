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
        $base = (string) (env('OPENAI_BASE_URL') ?: config('openai.base_url') ?: 'https://api.openai.com');
        $base = rtrim(trim($base), '/');
        if (str_ends_with($base, '/v1')) {
            $base = rtrim(substr($base, 0, -3), '/');
        }
        return $base;
    }

    protected function apiKey(): string
    {
        // Prefer env at runtime to reduce stale-key issues with long-lived workers.
        $key = (string) (env('OPENAI_API_KEY') ?: config('openai.api_key') ?: '');
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
     * TESTO (usa Responses API): ritorna array con caption/hashtags/cta/image_prompt
     * Docs: POST /v1/responses. :contentReference[oaicite:2]{index=2}
     */
    public function generateContent(array $context): array
    {
        $model = (string) (config('openai.text_model') ?: env('OPENAI_TEXT_MODEL') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);

        $instructions =
            "Sei una social media manager senior.\n"
            . "Usa strategia, profilo brand e direttive item_brain quando presenti nel contesto.\n"
            . "Rispetta repetition_rules: evita ripetizioni di hook, CTA e temi recenti.\n"
            . "Ogni post deve essere autosufficiente: comprensibile e utile anche da solo.\n"
            . "Mantieni comunque continuita strategica con campagne/serie quando presenti.\n"
            . "Ogni output deve essere distinto dai post recenti e dagli altri del piano.\n"
            . "Mantieni tono coerente con messaging_map tone_rules e regole do/dont.\n"
            . "Caption concreta, specifica e adatta alla piattaforma.\n"
            . "Usa item_brain.uniqueness_key come vincolo creativo anti-duplicato.\n"
            . "Il prompt immagine deve evitare loghi finti, watermark e testo sovraimpresso.\n"
            . "Testo, CTA, hashtag e prompt immagine devono essere in italiano.\n"
            . "Restituisci SOLO JSON valido con chiavi:\n"
            . "- caption (string)\n"
            . "- hashtags (array of strings)\n"
            . "- cta (string)\n"
            . "- image_prompt (string)\n"
            . "Niente markdown. Niente code fences. Nessun testo extra.";

        $input = [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => "Contesto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];

        $url = $this->url('/v1/responses');

        try {
            $res = Http::withToken($this->apiKey())
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry(2, 300)
                ->post($url, [
                    'model' => $model,
                    'input' => $input,
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI text error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();

            // Estrai testo: in Responses è dentro output[*].content[*].text
            $text = $this->extractResponsesText($data);
            $parsed = $this->safeJsonParse($text);
            $parsed = is_array($parsed) ? $parsed : [];

            // Normalizza hashtags
            $hashtags = $parsed['hashtags'] ?? [];
            if (is_string($hashtags)) {
                $hashtags = preg_split('/[\s,]+/', trim($hashtags)) ?: [];
                $hashtags = array_values(array_filter($hashtags));
            }
            if (!is_array($hashtags)) $hashtags = [];

            return [
                'caption' => $parsed['caption'] ?? null,
                'hashtags' => $hashtags,
                'cta' => $parsed['cta'] ?? null,
                'image_prompt' => $parsed['image_prompt'] ?? null,
            ];
        } catch (Throwable $e) {
            Log::error('OpenAiService generateContent failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * IMMAGINI: per gpt-image-* NON inviare response_format (errore 400).
     * Per GPT image models, b64_json arriva di default. :contentReference[oaicite:3]{index=3}
     */
    public function generateImageBase64(string $prompt, ?string $modelOverride = null): array
    {
        $model = (string) ($modelOverride ?: config('openai.image_model') ?: env('OPENAI_IMAGE_MODEL') ?: 'gpt-image-1');
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
                throw new RuntimeException("OpenAI image error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();

            // GPT image models: data[0].b64_json
            $b64 = (string) data_get($data, 'data.0.b64_json', '');
            $b64 = trim($b64);

            if ($b64 === '') {
                throw new RuntimeException('Missing data.0.b64_json in images response');
            }

            return [
                'b64' => $b64,
                'b64_json' => $b64,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('OpenAiService generateImageBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Image edit/variation partendo da una o più immagini locali.
     * Ritorna b64_json coerente con Images API.
     */
    public function generateImageEditBase64(string $prompt, array $imageAbsolutePaths, ?string $modelOverride = null): array
    {
        $model = (string) ($modelOverride ?: config('openai.image_model') ?: env('OPENAI_IMAGE_MODEL') ?: 'gpt-image-1');
        $timeout = (int) (config('openai.timeout_images') ?: 120);
        $url = $this->url('/v1/images/edits');

        $paths = array_values(array_filter($imageAbsolutePaths, fn ($p) => is_string($p) && is_file($p)));
        if (empty($paths)) {
            throw new RuntimeException('No valid image file provided for image edit.');
        }

        try {
            $req = Http::withToken($this->apiKey())
                ->acceptJson()
                ->timeout($timeout)
                ->retry(1, 400);

            foreach ($paths as $idx => $path) {
                $filename = basename($path);
                $mime = mime_content_type($path) ?: 'application/octet-stream';
                $req = $req->attach('image[]', file_get_contents($path), $filename, ['Content-Type' => $mime]);
                if ($idx >= 2) {
                    break;
                }
            }

            $res = $req->post($url, [
                'model' => $model,
                'prompt' => $prompt,
                'size' => config('openai.image_size') ?: '1024x1024',
            ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI image edit error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            $b64 = (string) data_get($data, 'data.0.b64_json', '');
            $b64 = trim($b64);

            if ($b64 === '') {
                throw new RuntimeException('Missing data.0.b64_json in image edit response');
            }

            return [
                'b64' => $b64,
                'b64_json' => $b64,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('OpenAiService generateImageEditBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
                'images_count' => count($paths),
            ]);
            throw $e;
        }
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
