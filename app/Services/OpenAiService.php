<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenAiService
{
    private function baseClient()
    {
        $key = config('openai.api_key');

        return Http::withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout(120);
    }

    /**
     * Genera JSON: caption, hashtags[], cta, image_prompt
     */
    public function generatePostPackage(array $context): array
    {
        $model = config('openai.text_model');
        $maxTokens = (int) config('openai.max_tokens', 900);
        $temperature = (float) config('openai.temperature', 0.7);

        $system = "Sei un social media strategist. Rispondi SOLO in JSON valido, senza testo extra.
Schema:
{
  \"caption\": \"...\",
  \"hashtags\": [\"#...\", \"#...\"],
  \"cta\": \"...\",
  \"image_prompt\": \"...\" 
}
Regole:
- caption in italiano, max 1200 caratteri
- hashtags 8-14, pertinenti
- cta breve e concreta
- image_prompt: descrizione per creare un'immagine coerente con il post (stile moderno, pulito, brand Smartera).";

        $user = "Contesto:
Brand: {$context['brand']}
Obiettivo: {$context['goal']}
Tone: {$context['tone']}
Piattaforme: {$context['platforms']}
Formato: {$context['format']}
Titolo contenuto: {$context['title']}
Data: {$context['date']}
Servizi: {$context['services']}
Target: {$context['target']}
Business: {$context['business']}
Extra: {$context['extra']}

Crea il pacchetto JSON.";

        $res = $this->baseClient()->post('https://api.openai.com/v1/responses', [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_output_tokens' => $maxTokens,
            'temperature' => $temperature,
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException("OpenAI text error: ".$res->status()." ".$res->body());
        }

        $data = $res->json();

        // Estraggo testo dall'output (Responses API)
        $text = $this->extractOutputText($data);

        $json = $this->safeJsonDecode($text);
        if (!is_array($json)) {
            throw new \RuntimeException("OpenAI returned non-JSON: ".$text);
        }

        // Normalizzazioni minime
        $json['hashtags'] = array_values(array_filter((array)($json['hashtags'] ?? [])));
        return $json;
    }

    /**
     * Genera immagine base64 usando tool image_generation (Responses).
     */
    public function generateImageBase64(string $prompt): string
    {
        $model = config('openai.image_model');

        $res = $this->baseClient()->post('https://api.openai.com/v1/responses', [
            'model' => $model,
            'input' => $prompt,
            'tools' => [
                ['type' => 'image_generation'],
            ],
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException("OpenAI image error: ".$res->status()." ".$res->body());
        }

        $data = $res->json();

        // Trova output.type === image_generation_call e prende result (base64)
        $out = $data['output'] ?? [];
        foreach ($out as $o) {
            if (($o['type'] ?? null) === 'image_generation_call' && !empty($o['result'])) {
                return $o['result'];
            }
        }

        throw new \RuntimeException("No image_generation_call result in response.");
    }

    private function extractOutputText(array $data): string
    {
        // Struttura tipica: output -> message -> content[] (type: output_text)
        $out = $data['output'] ?? [];
        $chunks = [];

        foreach ($out as $o) {
            if (($o['type'] ?? null) === 'message') {
                $content = $o['content'] ?? [];
                foreach ($content as $c) {
                    if (($c['type'] ?? null) === 'output_text' && isset($c['text'])) {
                        $chunks[] = $c['text'];
                    }
                }
            }
        }

        // fallback
        if (!$chunks && isset($data['output_text'])) {
            return (string) $data['output_text'];
        }

        return trim(implode("\n", $chunks));
    }

    private function safeJsonDecode(string $text): mixed
    {
        $text = trim($text);

        // Prova diretto
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;

        // Fallback: prova a estrarre primo { ... ultimo }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }

        return null;
    }
}
