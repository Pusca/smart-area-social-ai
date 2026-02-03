<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiService
{
    private string $apiKey;
    private string $baseUrl;
    private string $textModel;
    private string $imageModel;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        $this->apiKey = (string) config('openai.api_key');
        $this->baseUrl = $this->normalizeBaseUrl((string) config('openai.base_url'));
        $this->textModel = (string) config('openai.text_model');
        $this->imageModel = (string) config('openai.image_model');
        $this->maxTokens = (int) config('openai.max_tokens');
        $this->temperature = (float) config('openai.temperature');

        if (!$this->apiKey) {
            throw new RuntimeException('OPENAI_API_KEY mancante (controlla .env).');
        }

        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new RuntimeException('OPENAI_BASE_URL non valido: ' . $this->baseUrl);
        }
        if (!str_ends_with($this->baseUrl, '/v1')) {
            throw new RuntimeException('OPENAI_BASE_URL deve terminare con /v1. Attuale: ' . $this->baseUrl);
        }
    }

    /**
     * Genera contenuto testuale strutturato per un singolo item.
     * Ritorna: ['caption'=>string, 'hashtags'=>array, 'cta'=>string, 'image_prompt'=>string]
     */
    public function generateContent(array $context): array
    {
        $system = <<<SYS
Sei un social media strategist e copywriter italiano.
Devi produrre contenuti pronti per pubblicazione, coerenti col brand e con l'obiettivo.
Rispondi SOLO in JSON valido, senza testo extra.
Chiavi obbligatorie:
- caption (string)
- hashtags (array di string)
- cta (string)
- image_prompt (string)
SYS;

        $payload = [
            'model' => $this->textModel,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE)],
            ],
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        $url = $this->url('chat/completions');

        $res = Http::withToken($this->apiKey)
            ->timeout(90)
            ->acceptJson()
            ->contentType('application/json')
            ->post($url, $payload);

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI text error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $content = data_get($res->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException("OpenAI text: risposta vuota. URL={$url}");
        }

        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new RuntimeException("OpenAI text: JSON non valido. URL={$url} Raw=" . $content);
        }

        $hashtags = $data['hashtags'] ?? [];
        if (!is_array($hashtags)) $hashtags = [];

        $hashtags = array_values(array_filter(array_map(
            fn($h) => is_string($h) ? trim($h) : '',
            $hashtags
        )));

        return [
            'caption' => (string)($data['caption'] ?? ''),
            'hashtags' => $hashtags,
            'cta' => (string)($data['cta'] ?? ''),
            'image_prompt' => (string)($data['image_prompt'] ?? ''),
        ];
    }

    /**
     * Genera immagine e ritorna base64 (PNG)
     * Ritorna: ['b64'=>string, 'mime'=>string]
     */
    public function generateImageBase64(string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Image prompt vuoto.');
        }

        $payload = [
            'model' => $this->imageModel,
            'prompt' => $prompt,
            'size' => '1024x1024',
            'response_format' => 'b64_json',
        ];

        $url = $this->url('images/generations');

        $res = Http::withToken($this->apiKey)
            ->timeout(120)
            ->acceptJson()
            ->contentType('application/json')
            ->post($url, $payload);

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI image error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $b64 = data_get($res->json(), 'data.0.b64_json');
        if (!is_string($b64) || $b64 === '') {
            throw new RuntimeException("OpenAI image: b64 mancante. URL={$url}");
        }

        return ['b64' => $b64, 'mime' => 'image/png'];
    }

    private function url(string $path): string
    {
        $path = ltrim($path, '/');
        return $this->baseUrl . '/' . $path;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') $baseUrl = 'https://api.openai.com/v1';

        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }

        $baseUrl = rtrim($baseUrl, '/');

        if (!str_ends_with($baseUrl, '/v1')) {
            $baseUrl .= '/v1';
        }

        return $baseUrl;
    }

    private function extractJson(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return $raw;
        }

        return substr($raw, $start, $end - $start + 1);
    }
}
