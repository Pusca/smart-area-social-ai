<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAiService
{
    /**
     * Normalizza base URL: accetta con o senza /v1 finale,
     * restituisce sempre host senza /v1.
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
        return $this->baseHost() . $path;
    }

    protected function language(): string
    {
        return (string) (config('openai.language') ?: 'italiano');
    }

    /**
     * TESTO di un singolo post (Responses API + structured outputs),
     * con pass opzionale di revisione editoriale.
     *
     * $context: ['brand' => [...], 'plan' => [...], 'item' => [...], 'topic' => [...]|null]
     * Ritorna: caption, hashtags[], cta, image_prompt, usage.
     */
    public function generateContent(array $context): array
    {
        $platform = (string) data_get($context, 'item.platform', 'instagram');
        $format = (string) data_get($context, 'item.format', 'post');

        $schema = $this->socialPostSchema();
        $instructions = $this->writerInstructions($platform, $format, $context);

        $draft = $this->responsesCall($instructions, $this->contextInput($context), 'social_post', $schema);
        $parsed = $draft['parsed'];
        $usage = [$draft['usage']];

        if (config('openai.refine_pass') && !empty($parsed['caption'])) {
            try {
                $refined = $this->refineContent($context, $parsed, $platform, $format);
                if (!empty($refined['parsed']['caption'])) {
                    $parsed = $refined['parsed'];
                    $usage[] = $refined['usage'];
                }
            } catch (Throwable $e) {
                // Il draft resta valido anche se la revisione fallisce
                Log::warning('OpenAiService refine pass failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'caption' => $parsed['caption'] ?? null,
            'hashtags' => $this->normalizeHashtags($parsed['hashtags'] ?? []),
            'cta' => $parsed['cta'] ?? null,
            'image_prompt' => $parsed['image_prompt'] ?? null,
            'usage' => $usage,
        ];
    }

    protected function writerInstructions(string $platform, string $format, array $context): string
    {
        $language = $this->language();
        $rules = $this->platformRules($platform) . "\n" . $this->formatRules($format);

        // I fatti del brand vengono promossi a istruzioni: nel solo blob JSON
        // il modello tende a ignorarli.
        $factsBlock = '';
        $factLines = [];
        $factMap = [
            'industry' => 'Settore',
            'services' => 'Servizi/prodotti reali (parla di questi)',
            'target' => 'Clienti tipo',
            'cta' => 'CTA preferita dal brand',
            'notes' => 'Punti di forza e note distintive',
        ];
        foreach ($factMap as $key => $label) {
            $value = trim((string) data_get($context, "brand.{$key}", ''));
            if ($value !== '') {
                $factLines[] = "- {$label}: {$value}";
            }
        }
        if ($factLines !== []) {
            $factsBlock = "\n\nScheda attività (unica fonte di verità sui fatti):\n" . implode("\n", $factLines);
        }

        $avoidBlock = '';
        $avoid = data_get($context, 'avoid_openings', []);
        if (is_array($avoid) && $avoid !== []) {
            $avoidBlock = "\n\nAperture già usate da altri post di questo piano — NON ripeterle né parafrasarle, parti in modo diverso:\n- "
                . implode("\n- ", array_map('strval', $avoid));
        }

        $scheduleBlock = '';
        $day = trim((string) data_get($context, 'item.scheduled_day', ''));
        if ($day !== '') {
            $scheduleBlock = "\n\nIl post uscirà {$day}: se il momento è rilevante (weekend, stagione, festività), sfruttalo; altrimenti ignoralo senza citare la data.";
        }

        $voiceBlock = '';
        $voice = trim((string) data_get($context, 'brand.brand_voice', ''));
        if ($voice !== '') {
            $voiceBlock = "\n\nVoce del brand (rispettala in ogni frase):\n{$voice}";
        }

        $examplesBlock = '';
        $examples = trim((string) data_get($context, 'brand.example_posts', ''));
        if ($examples !== '') {
            $examplesBlock = "\n\nEsempi di post scritti dal brand (imita stile, ritmo e lessico, NON i contenuti):\n{$examples}";
        }

        $visualBlock = '';
        $visual = trim((string) data_get($context, 'brand.visual_style', ''));
        if ($visual !== '') {
            $visualBlock = "\n\nStile visivo del brand (usalo per \"image_prompt\"):\n{$visual}";
        }

        return
            "Sei un social media manager e copywriter senior specializzato in piccole e medie attività.\n"
            . "Scrivi contenuti in {$language}, pronti da pubblicare, specifici per il brand e l'argomento indicati: mai generici, mai riempitivi.\n\n"
            . "Piattaforma: {$platform} (formato: {$format}).\n"
            . $rules
            . $factsBlock
            . $voiceBlock
            . $examplesBlock
            . $visualBlock
            . $avoidBlock
            . $scheduleBlock . "\n\n"
            . "Regole generali:\n"
            . "- La caption apre con un hook forte e sviluppa UN solo argomento (quello in \"topic\", se presente), toccando i suoi \"key_points\".\n"
            . "- Usa dettagli concreti presi dal contesto (servizi, target, zona): un lettore deve capire che parla proprio di questa attività.\n"
            . "- Vietate le frasi fatte da AI (\"nel cuore di\", \"scopri il mondo di\", \"non è solo un..., è un'esperienza\").\n"
            . "- Non inventare dati, prezzi, promozioni o recapiti non presenti nel contesto.\n"
            . "- La CTA deve essere coerente con quella preferita dal brand, se indicata.\n"
            . "- Ogni hashtag inizia con # ed è senza spazi.\n"
            . "- \"image_prompt\": in inglese, descrittivo e fotografico (soggetto, ambiente, luce, stile), coerente con lo stile visivo del brand e l'argomento, senza testo né loghi nell'immagine.";
    }

    /**
     * Secondo pass: un "editor severo" migliora hook, specificità e ritmo del draft.
     */
    protected function refineContent(array $context, array $draft, string $platform, string $format): array
    {
        $language = $this->language();
        $rules = $this->platformRules($platform) . "\n" . $this->formatRules($format);

        $instructions =
            "Sei un editor senior di contenuti social, severo e concreto. Lingua: {$language}.\n"
            . "Ti viene passato un post in bozza (\"draft\") con il suo contesto. Miglioralo e restituiscilo nello stesso formato.\n\n"
            . "Piattaforma: {$platform} (formato: {$format}).\n"
            . $rules . "\n\n"
            . "Cosa sistemare:\n"
            . "- Hook: i primi 100 caratteri devono creare curiosità o toccare un problema reale del target.\n"
            . "- Specificità: sostituisci ogni frase generica con un dettaglio concreto preso dal contesto; se una frase potrebbe stare nel post di qualunque azienda, riscrivila.\n"
            . "- Elimina cliché da AI, riempitivi ed emoji in eccesso.\n"
            . "- Ritmo: frasi brevi, paragrafi ariosi, chiusura con la CTA.\n"
            . "- Non inventare nulla che non sia nel contesto. Migliora anche \"image_prompt\" se troppo generico.";

        $input = $this->contextInput($context)
            . "\n\nDraft da migliorare:\n"
            . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->responsesCall($instructions, $input, 'social_post', $this->socialPostSchema());
    }

    protected function socialPostSchema(): array
    {
        return [
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
    }

    /**
     * IDEAZIONE PIANO: genera $count argomenti distinti e complementari.
     * $context: ['brand' => [...], 'plan' => [...], 'schedule' => [...], 'avoid_topics' => [...]]
     * Ritorna: ['topics' => [[title, angle, key_points[]], ...], 'usage' => [...]]
     */
    public function generatePlanTopics(array $context, int $count): array
    {
        $language = $this->language();

        $instructions =
            "Sei uno stratega di contenuti social per piccole e medie attività.\n"
            . "Genera ESATTAMENTE {$count} idee di post per il piano editoriale descritto nel contesto, in {$language}.\n\n"
            . "Regole:\n"
            . "- Ogni idea tratta un argomento DIVERSO: nessuna ripetizione, nessuna variazione dello stesso concetto.\n"
            . "- Gli argomenti in \"avoid_topics\" sono GIÀ STATI USATI: non riproporli né parafrasarli.\n"
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
        $maxTokens = max((int) (config('openai.max_output_tokens') ?: 2500), 220 * $count);

        $data = $this->responsesCall(
            $instructions,
            $this->contextInput($context),
            'plan_topics',
            $schema,
            $maxTokens,
            (string) (config('openai.ideation_model') ?: config('openai.text_model')),
            (string) (config('openai.ideation_reasoning_effort') ?: 'medium')
        );

        $topics = $data['parsed']['topics'] ?? [];
        if (!is_array($topics) || $topics === []) {
            throw new RuntimeException('Nessun topic generato per il piano');
        }

        return ['topics' => array_values($topics), 'usage' => $data['usage']];
    }

    /**
     * Compila il profilo attività a partire dal testo del sito web del cliente.
     */
    public function extractBrandProfile(string $url, string $websiteText): array
    {
        $language = $this->language();

        $instructions =
            "Analizza il testo estratto dal sito web {$url} e compila il profilo dell'attività in {$language}.\n"
            . "Regole:\n"
            . "- Usa SOLO informazioni presenti nel testo: se un campo non è deducibile, lascialo stringa vuota.\n"
            . "- \"services\": elenco conciso di servizi/prodotti reali, separati da riga.\n"
            . "- \"target\": chi sono i clienti tipo (età, bisogni, zona se indicata).\n"
            . "- \"brand_voice\": 2-3 frasi che descrivono tono e personalità comunicativa che emergono dal sito.\n"
            . "- \"cta\": l'invito all'azione più naturale per questa attività (es. prenota, richiedi preventivo).\n"
            . "- \"notes\": punti di forza, valori o dettagli distintivi utili a scrivere post.\n"
            . "- \"default_tone\": il tono più adatto a come comunica il sito, scelto tra le opzioni disponibili.";

        $schema = [
            'type' => 'object',
            'properties' => [
                'business_name' => ['type' => 'string'],
                'industry' => ['type' => 'string'],
                'services' => ['type' => 'string'],
                'target' => ['type' => 'string'],
                'cta' => ['type' => 'string'],
                'brand_voice' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'default_tone' => [
                    'type' => 'string',
                    'enum' => ['professionale', 'amichevole', 'ironico', 'ispirazionale', 'tecnico'],
                ],
            ],
            'required' => ['business_name', 'industry', 'services', 'target', 'cta', 'brand_voice', 'notes', 'default_tone'],
            'additionalProperties' => false,
        ];

        $data = $this->responsesCall(
            $instructions,
            "Testo del sito web:\n" . $websiteText,
            'brand_profile',
            $schema
        );

        return $data['parsed'];
    }

    /**
     * Sintetizza la voce del brand dai suoi post social REALI.
     *
     * @param list<string> $captions
     */
    public function describeBrandVoiceFromPosts(array $captions): string
    {
        if ($captions === []) {
            return '';
        }

        $language = $this->language();

        $instructions =
            "Questi sono post social REALI pubblicati da un'attività. Analizzali e descrivi in {$language}, "
            . "in 2-3 frasi, la voce del brand: tono, registro (tu/voi/lei), ritmo, uso di emoji, "
            . "espressioni ricorrenti. Descrivi solo ciò che emerge davvero dai post.";

        $schema = [
            'type' => 'object',
            'properties' => ['brand_voice' => ['type' => 'string']],
            'required' => ['brand_voice'],
            'additionalProperties' => false,
        ];

        $data = $this->responsesCall(
            $instructions,
            "Post reali del brand:\n\n" . implode("\n\n---\n\n", array_slice($captions, 0, 12)),
            'brand_voice',
            $schema
        );

        return trim((string) ($data['parsed']['brand_voice'] ?? ''));
    }

    /**
     * Descrive lo stile visivo del brand a partire dalle sue foto reali
     * (logo, locale, prodotti). Il risultato guida gli image_prompt.
     *
     * @param list<string> $imageDataUrls data URL base64 (max ~4)
     */
    public function describeBrandVisuals(array $imageDataUrls): string
    {
        if ($imageDataUrls === []) {
            return '';
        }

        $instructions =
            "You are a brand art director. Look at these real photos from a small business "
            . "(logo, location, products, people) and describe the brand's visual style in 60-100 English words: "
            . "color palette, lighting, subjects, composition, mood. "
            . "Write it as a reusable style guide for generating on-brand social media images. "
            . "Describe only what you can actually see.";

        $content = [['type' => 'input_text', 'text' => 'Brand photos:']];
        foreach (array_slice($imageDataUrls, 0, 4) as $dataUrl) {
            $content[] = ['type' => 'input_image', 'image_url' => $dataUrl];
        }

        $schema = [
            'type' => 'object',
            'properties' => ['visual_style' => ['type' => 'string']],
            'required' => ['visual_style'],
            'additionalProperties' => false,
        ];

        $data = $this->responsesCall(
            $instructions,
            [['role' => 'user', 'content' => $content]],
            'visual_style',
            $schema
        );

        return trim((string) ($data['parsed']['visual_style'] ?? ''));
    }

    /**
     * IMMAGINI: per gpt-image-* NON inviare response_format (errore 400).
     * b64_json arriva di default per i GPT image models.
     */
    public function generateImageBase64(string $prompt, ?string $modelOverride = null, ?string $size = null): array
    {
        $model = trim((string) ($modelOverride ?: config('openai.image_model') ?: 'gpt-image-2'));
        // Guardia: mai chiamare le Images API con un modello testo
        if ($model === '' || (!str_contains($model, 'image') && !str_starts_with($model, 'dall-e'))) {
            $model = 'gpt-image-2';
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
                    'size' => $size ?: (config('openai.image_size') ?: '1024x1024'),
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

    protected function contextInput(array $context): string
    {
        return "Contesto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Chiamata Responses API con structured output (json_schema strict).
     * $input: stringa oppure array di messaggi (per input multimodali).
     * Ritorna ['parsed' => array, 'usage' => array].
     */
    protected function responsesCall(
        string $instructions,
        string|array $input,
        string $schemaName,
        array $schema,
        ?int $maxTokens = null,
        ?string $model = null,
        ?string $effort = null
    ): array {
        $model = $model ?: (string) (config('openai.text_model') ?: 'gpt-5-mini');
        $timeout = (int) (config('openai.timeout') ?: 90);
        $maxTokens = $maxTokens ?: (int) (config('openai.max_output_tokens') ?: 2500);

        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => $maxTokens,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        // I modelli reasoning (gpt-5.x, o*) accettano l'effort: per il copy basta "low"
        if (preg_match('/^(gpt-5|o\d)/', $model)) {
            $payload['reasoning'] = ['effort' => (string) ($effort ?: config('openai.reasoning_effort') ?: 'low')];
        }

        try {
            $res = Http::withToken($this->apiKey())
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry(2, 300)
                ->post($this->url('/v1/responses'), $payload);

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

    /**
     * Il formato cambia cosa si scrive, non solo l'aspect ratio dell'immagine.
     */
    protected function formatRules(string $format): string
    {
        return match ($format) {
            'reel' =>
                "- È la caption di un VIDEO breve: prima riga = il gancio parlato del video, poi il payoff in 2-3 frasi.\n"
                . "- \"image_prompt\": la scena di copertina del video, verticale, dinamica.",
            'story' =>
                "- È una story: 1-2 frasi secche + un invito interattivo (domanda, sondaggio, \"scrivici\").\n"
                . "- Niente blocchi di testo: la story si guarda, non si legge.",
            'live' =>
                "- Annuncio di una diretta: crea attesa, di' cosa si porta a casa chi partecipa; se data/ora sono nel contesto, mettile in evidenza.",
            'blog', 'newsletter' =>
                "- Formato lungo: struttura con un'idea forte per paragrafo e una sola CTA in chiusura.",
            default =>
                "- Post da feed: hook, sviluppo del punto, chiusura con CTA.",
        };
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
