<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class BrandParsingService extends OpenAiService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Sei un assistente che aiuta imprenditori a configurare il profilo brand per una piattaforma di social media AI.
L'utente parla liberamente (anche tramite microfono, quindi il testo può avere errori di trascrizione).

COMPITO: estrarre i seguenti campi dal testo dell'utente, anche in modo implicito:
- business_name: nome del brand/azienda — OBBLIGATORIO
- industry: settore o tipo di attività — OBBLIGATORIO
- services: prodotti o servizi offerti — OBBLIGATORIO
- target: pubblico di riferimento (chi sono i clienti) — OBBLIGATORIO
- default_tone: tono di voce — OBBLIGATORIO — SOLO uno di: professionale, amichevole, ironico, ispirazionale, tecnico
- default_goal: obiettivo social principale — OBBLIGATORIO — SOLO uno di: awareness, engagement, lead, conversion, trust
- default_platforms: array di piattaforme — opzionale — valori possibili: instagram, facebook, tiktok, linkedin, google_business
- vision: missione o visione — opzionale
- values: valori del brand — opzionale
- cta: call-to-action principale — opzionale
- notes: qualsiasi altra info utile — opzionale

REGOLE DI ESTRAZIONE:
- Estrai SEMPRE tutto ciò che puoi dal testo, anche implicitamente
- "mi chiamo Luca ho una pizzeria" → business_name: "Pizzeria di Luca", industry: "ristorazione", services: "pizza"
- "vendiamo scarpe di lusso" → industry: "moda/calzature", services: "scarpe di lusso"
- "parliamo ai giovani" → target: "giovani"
- "voglio far conoscere il brand" → default_goal: "awareness"
- "siamo un'azienda seria e professionale" → default_tone: "professionale"
- Se il settore si capisce dal nome o dai servizi, estrailo anche se non detto esplicitamente
- Correggere piccoli errori di trascrizione vocale (es. "pizzerìa" → "pizzeria")
- Nella "reply" fai UNA domanda concisa per il prossimo campo mancante più importante

RISPOSTA: JSON puro, nessun altro testo. Esempio:
{
  "reply": "Ottimo! Parliamo ai giovani — qual è il vostro tono di comunicazione? Siete ironici, professionali o ispiranti?",
  "extracted": {
    "business_name": "Sneaker House",
    "industry": "moda streetwear",
    "services": "scarpe e abbigliamento streetwear",
    "target": "giovani 18-30 appassionati di moda",
    "default_tone": null,
    "default_goal": null,
    "default_platforms": ["instagram", "tiktok"],
    "vision": null,
    "values": null,
    "cta": null,
    "notes": null
  },
  "missing_fields": ["default_tone", "default_goal"],
  "complete": false
}

IMPORTANTE:
- I valori in "extracted" devono essere stringhe reali o null — MAI testo come "valore o null"
- default_tone DEVE essere uno di: professionale, amichevole, ironico, ispirazionale, tecnico
- default_goal DEVE essere uno di: awareness, engagement, lead, conversion, trust
- "complete": true SOLO quando tutti e 6 i campi obbligatori hanno un valore non nullo
- Accumula i dati estratti nei turni precedenti — non azzerare ciò che hai già estratto
PROMPT;

    /**
     * Conversa con l'AI per raccogliere dati brand.
     *
     * @param  array<int,array{role:string,content:string}>  $messages  Storico conversazione
     * @param  array<string,mixed>  $existingProfile  Dati brand già presenti nel profilo
     * @return array{reply:string,extracted:array,missing_fields:array,complete:bool}
     */
    public function chat(array $messages, array $existingProfile = []): array
    {
        $systemContent = self::SYSTEM_PROMPT;

        if (!empty($existingProfile)) {
            $systemContent .= "\n\nDATA GIÀ PRESENTI NEL PROFILO (usa questi come punto di partenza, non chiedere di nuovo):\n"
                . json_encode($existingProfile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $openaiMessages = array_merge(
            [['role' => 'system', 'content' => $systemContent]],
            $messages
        );

        $model = config('openai.text_model', 'gpt-4.1-mini');

        $response = $this->request(60)->post($this->url('/v1/chat/completions'), [
            'model'           => $model,
            'messages'        => $openaiMessages,
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.7,
            'max_tokens'      => 1000,
        ]);

        if ($response->failed()) {
            Log::error('BrandParsingService: OpenAI error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Errore AI: ' . $response->status());
        }

        $content = $response->json('choices.0.message.content', '{}');
        $parsed  = json_decode($content, true);

        if (!is_array($parsed)) {
            Log::error('BrandParsingService: invalid JSON from AI', ['content' => $content]);
            throw new \RuntimeException('Risposta AI non valida.');
        }

        $extracted = (array) ($parsed['extracted'] ?? []);
        $missing   = $this->computeMissingFields($extracted);

        return [
            'reply'         => (string) ($parsed['reply'] ?? 'Ciao! Raccontami del tuo brand.'),
            'extracted'     => $extracted,
            'missing_fields'=> $missing,
            'complete'      => empty($missing) && (bool) ($parsed['complete'] ?? false),
        ];
    }

    /**
     * Applica i dati estratti al TenantProfile.
     *
     * @param  \App\Models\TenantProfile  $profile
     * @param  array<string,mixed>        $extracted
     */
    public function mergeIntoProfile(\App\Models\TenantProfile $profile, array $extracted): void
    {
        $fieldMap = [
            'business_name'    => 'business_name',
            'industry'         => 'industry',
            'services'         => 'services',
            'target'           => 'target',
            'default_tone'     => 'default_tone',
            'default_goal'     => 'default_goal',
            'default_platforms'=> 'default_platforms',
            'vision'           => 'vision',
            'values'           => 'values',
            'cta'              => 'cta',
            'notes'            => 'notes',
        ];

        $updates = [];
        foreach ($fieldMap as $aiKey => $dbKey) {
            $value = $extracted[$aiKey] ?? null;
            if ($value !== null && $value !== '') {
                $updates[$dbKey] = $value;
            }
        }

        if (empty($updates)) {
            return;
        }

        // Se il profilo non esiste ancora in DB, l'INSERT richiede business_name (NOT NULL).
        // Non salviamo finché non abbiamo almeno quel campo.
        if (!$profile->exists && empty($updates['business_name'])) {
            return;
        }

        $profile->fill($updates)->save();
    }

    /**
     * Trascrive un file audio tramite OpenAI Whisper.
     *
     * @param  string  $path  Percorso temporaneo del file audio
     * @param  string  $originalName  Nome originale con estensione corretta
     * @return string  Testo trascritto
     */
    public function transcribe(string $path, string $originalName = 'audio.webm'): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'webm');
        $mime = match ($ext) {
            'ogg'  => 'audio/ogg',
            'mp4'  => 'audio/mp4',
            'm4a'  => 'audio/mp4',
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'flac' => 'audio/flac',
            default => 'audio/webm',
        };

        $response = $this->request(120, false)
            ->attach('file',     file_get_contents($path), $originalName, ['Content-Type' => $mime])
            ->attach('model',    'whisper-1')
            ->attach('language', 'it')
            ->post($this->url('/v1/audio/transcriptions'));

        if ($response->failed()) {
            Log::error('BrandParsingService: Whisper error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Errore trascrizione: ' . $response->status() . ' — ' . $response->body());
        }

        return trim((string) $response->json('text', ''));
    }

    private function computeMissingFields(array $extracted): array
    {
        $required = ['business_name', 'industry', 'services', 'target', 'default_tone', 'default_goal'];
        return array_values(array_filter($required, fn ($f) => empty($extracted[$f])));
    }
}
