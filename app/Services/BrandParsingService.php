<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class BrandParsingService extends OpenAiService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Sei un assistente esperto in branding che aiuta imprenditori a configurare il loro profilo brand per una piattaforma di social media AI.

Il tuo compito è raccogliere, tramite conversazione naturale, le seguenti informazioni sul brand dell'utente:
- business_name: nome del brand/azienda (OBBLIGATORIO)
- industry: settore/industria (OBBLIGATORIO)
- services: prodotti o servizi principali offerti (OBBLIGATORIO)
- target: pubblico di riferimento (OBBLIGATORIO)
- default_tone: tono di comunicazione. SOLO uno di questi valori: professionale, amichevole, ironico, ispirazionale, tecnico (OBBLIGATORIO)
- default_goal: obiettivo principale social. SOLO uno di questi valori: awareness, engagement, lead, conversion, trust (OBBLIGATORIO)
- default_platforms: array di piattaforme social. Valori possibili: instagram, facebook, tiktok, linkedin, google_business (opzionale)
- vision: visione o missione del brand (opzionale)
- values: valori del brand (opzionale)
- cta: call-to-action principale (opzionale)
- notes: note aggiuntive rilevanti (opzionale)

REGOLE DI CONVERSAZIONE:
1. Inizia accogliendo l'utente e chiedendo del brand in modo naturale
2. Poni 1-2 domande per volta, non fare un elenco di domande
3. Estrai i dati implicitamente dal testo dell'utente - non chiedere sempre in modo formale
4. Adatta il tono della conversazione al brand che l'utente descrive
5. Quando hai raccolto i 6 campi obbligatori, proponi un riepilogo e chiedi conferma
6. Conferma quando sei "complete": tutti e 6 i campi obbligatori sono valorizzati

FORMATO RISPOSTA (JSON PURO, nient'altro):
{
  "reply": "La tua risposta conversazionale in italiano",
  "extracted": {
    "business_name": "valore o null",
    "industry": "valore o null",
    "services": "valore o null",
    "target": "valore o null",
    "default_tone": "professionale|amichevole|ironico|ispirazionale|tecnico o null",
    "default_goal": "awareness|engagement|lead|conversion|trust o null",
    "default_platforms": ["instagram"] o null,
    "vision": "valore o null",
    "values": "valore o null",
    "cta": "valore o null",
    "notes": "valore o null"
  },
  "missing_fields": ["lista dei campi obbligatori ancora mancanti"],
  "complete": false
}

IMPORTANTE: restituisci SEMPRE un JSON valido, mai testo libero.
Accumula i dati estratti dalle conversazioni precedenti — non resettare ciò che hai già estratto.
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

        if (!empty($updates)) {
            $profile->fill($updates)->save();
        }
    }

    private function computeMissingFields(array $extracted): array
    {
        $required = ['business_name', 'industry', 'services', 'target', 'default_tone', 'default_goal'];
        return array_values(array_filter($required, fn ($f) => empty($extracted[$f])));
    }
}
