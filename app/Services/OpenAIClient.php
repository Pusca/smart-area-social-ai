<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAIClient
{
    public function generateStructured(array $messages, array $jsonSchema, int $maxOutputTokens = 900): array
    {
        $apiKey = config('openai.api_key');
        $model  = config('openai.model');
        $base   = rtrim(config('openai.base_url'), '/');

        if (!$apiKey) {
            throw new RuntimeException('OPENAI_API_KEY mancante in .env');
        }

        // Structured Outputs via json_schema (consigliato quando possibile). :contentReference[oaicite:3]{index=3}
        $payload = [
            'model' => $model,
            'input' => $messages,
            'max_output_tokens' => $maxOutputTokens,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'generated_post',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ],
            ],
        ];

        $res = Http::withToken($apiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->post($base . '/responses', $payload);

        if (!$res->successful()) {
            $body = $res->json();
            throw new RuntimeException('OpenAI error: ' . json_encode($body ?? ['status' => $res->status()]));
        }

        $data = $res->json();

        // Estrae il testo "output_text" dalla struttura output[].content[].text :contentReference[oaicite:4]{index=4}
        $text = $this->extractOutputText($data);

        // Il text in modalità json_schema è JSON valido (stringa) => decode
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Risposta non decodificabile come JSON. Raw: ' . Str::limit($text, 4000));
        }

        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        $out = $response['output'] ?? [];
        $chunks = [];

        foreach ($out as $item) {
            if (($item['type'] ?? null) !== 'message') continue;
            if (($item['role'] ?? null) !== 'assistant') continue;

            foreach (($item['content'] ?? []) as $c) {
                if (($c['type'] ?? null) === 'output_text' && isset($c['text'])) {
                    $chunks[] = $c['text'];
                }
            }
        }

        $text = trim(implode("\n", $chunks));
        if ($text === '') {
            throw new RuntimeException('Nessun output_text trovato nella risposta OpenAI.');
        }
        return $text;
    }
}
