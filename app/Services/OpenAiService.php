<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    protected function request(int $timeout, bool $asJson = true): PendingRequest
    {
        $req = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout((int) (config('openai.connect_timeout') ?: 15));

        if ($asJson) {
            $req = $req->asJson();
        }

        $proxy = trim((string) (config('openai.proxy') ?: env('OPENAI_PROXY') ?: ''));
        $forceIpv4 = (bool) (config('openai.force_ipv4') ?: env('OPENAI_FORCE_IPV4') ?: false);
        $options = [];

        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        if ($forceIpv4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options['curl'] = [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];
        }

        if (!empty($options)) {
            $req = $req->withOptions($options);
        }

        return $req;
    }

    /**
     * TESTO (usa Responses API): ritorna array con caption/hashtags/cta/image_prompt
     * Docs: POST /v1/responses. :contentReference[oaicite:2]{index=2}
     */
    public function generateContent(array $context): array
    {
        $model = trim((string) data_get($context, 'provider_matrix.text.model_override', ''));
        if ($model === '') {
            $model = (string) (config('openai.text_model') ?: env('OPENAI_TEXT_MODEL') ?: 'gpt-4.1-mini');
        }
        $timeout = (int) (config('openai.timeout') ?: 60);
        $maxOutputTokens = max(600, (int) (config('openai.text_max_output_tokens') ?: 1400));

        // ── Visual mode GPT instruction (prepended so the model reads it first) ──
        $visualModeGptInstruction = trim((string) data_get($context, 'visual_mode_preset.gpt_instruction', ''));

        $instructions = ($visualModeGptInstruction !== '' ? $visualModeGptInstruction . "\n\n" : '')
            . "Sei una social media manager senior.\n"
            . "Usa strategia, profilo brand e direttive item_brain quando presenti nel contesto.\n"
            . "Se creative_brief e presente, trattalo come artefatto canonico pre-generazione: objective, hook_strategy, proof_points, identity_constraints, trend_overlays e publishability_constraints hanno priorita strategica.\n"
            . "Se trend_brief e presente, usa current_relevant_themes, recommended_post_angles, recommended_reel_structures, recommended_hook_patterns, recommended_cta_styles e trends_to_avoid come base fresca ed esplicabile.\n"
            . "Se tenant_learning e presente, usa preferred_hook_families, preferred_cta_styles, formats_that_underperform e provider_paths_to_avoid_for_identity_heavy per correggere le scelte creative.\n"
            . "Se strategy_blueprint.creative_direction e presente, usala come sistema operativo creativo: professional_direction per posizionamento e qualita percepita, trend_policy per usare trend solo in modo brand-safe, typography_system per lasciare layout overlay-ready senza far scrivere testo lungo al modello, continuity_rules per preservare identita reali di persone, luoghi e prodotti.\n"
            . "Usa memory_summary.feedback_summary come memoria del gusto del tenant: cio che piace va riutilizzato con intelligenza, cio che non piace va evitato.\n"
            . "Usa knowledge_pack come dossier operativo del cliente: identita, segnali positivi, divieti, temi, offerte e preferenze hanno priorita reale.\n"
            . "Dentro knowledge_pack.asset_library trovi foto, video, logo, audio, documenti, note testuali e link reali del cliente: usali per capire soggetti, prodotti, luoghi, voce, regole operative, FAQ e riferimenti concreti da preservare.\n"
            . "Quando documenti, testi o link contengono dettagli utili su servizi, limiti, offerte, processi o tono del brand, trattali come grounding operativo prioritario e non come contesto opzionale.\n"
            . "Usa examples come esempi approvati del tenant: assorbi struttura, tono e concretezza, ma non copiare frasi, hook o CTA.\n"
            . "Usa negative_examples per capire cosa NON ripetere: evita gli errori gia segnalati dal cliente.\n"
            . "Tratta memory_summary.hard_avoid_rules e feedback_loop.tenant_feedback.recent_objections come vincoli forti.\n"
            . "Se feedback_loop.active_request e presente, e una correzione prioritaria da applicare subito nella rigenerazione corrente.\n"
            . "Se asset_variables contiene una persona guidata o un persona pack, trattala come identita reale da preservare: stesso volto, stessi lineamenti, stessa eta apparente e tratti distintivi coerenti tra contenuti diversi.\n"
            . "Quando una persona guidata ha immutable_traits, role, look_notes o prompt_notes, usali come istruzioni operative forti per caption, image_prompt e video_prompt.\n"
            . "Se il contesto contiene asset_identity, trattalo come contratto operativo del contenuto: presenter, product e place sono identita persistenti da mantenere coerenti.\n"
            . "In asset_identity.locked_elements trovi cio che non deve cambiare; in asset_identity.allowed_changes trovi cio che puoi variare per tema, stagione o campagna.\n"
            . "Se asset_identity.consistency_mode e strict, privilegia editing e variazioni controllate; se e balanced, varia scena e styling ma non il soggetto; se e creative, resta coerente ma concedi piu liberta compositiva.\n"
            . "Quando la correzione riguarda il visual, migliora soprattutto prompt immagine e coerenza visuale, preservando il resto se gia valido.\n"
            . "Quando la correzione riguarda copy o tono, migliora soprattutto caption, CTA e angolo narrativo, preservando il visual se gia coerente.\n"
            . "Se alter_ego e presente e non vuoto, tratta alter_ego.persona_prompt come vincolo assoluto di voce, tono e stile narrativo: ogni caption, CTA e prompt visuale deve riflettere quella persona come se stesse scrivendo direttamente. Archetipo, tono, frasi caratteristiche e temi di proprieta hanno priorita su qualsiasi stile di default del brand.\n"
            . "Tutto cio che generi e pensato per post social: non brochure, non sito corporate, non stock generico.\n"
            . "Usa social_publication_context per capire ruolo del contenuto nel feed, nella serie e nell insieme delle pubblicazioni.\n"
            . "Se content_strategy_blueprint e presente, trattalo come guida editoriale forte per hook, angle, proof e CTA.\n"
            . "Se item_brain.hook_meta e presente, usa main_hook come apertura preferita, alternative_hook solo se la prima suona forzata, audience_trigger per capire quale leva attivare, authority_cue e proof_or_trust_cue per dare credibilita concreta.\n"
            . "Se item_brain.authority_signals o item_brain.trust_signals sono presenti, incorporali nel copy come dettagli reali o proof cue naturali, non come elenco tecnico esplicito.\n"
            . "Se item_brain.content_structure_meta e presente, rispetta opening_structure, body_flow, closing_structure e, per i video, video_segments come ritmo operativo del contenuto.\n"
            . "Se strategy_blueprint.creative_direction.content_strategy e presente, usalo come guard-rail per mantenere hook professionali, proof credibile e CTA non manipolative.\n"
            . "Se item_brain.trend_bridge e presente, usa il trend come meccanica o angolo adattato al brand, non come meme o imitazione gratuita.\n"
            . "Se item_brain.trend_opportunity e presente, usa hook_patterns, recommended_angle e risk_flags come guida operativa: massimizza modernita e viral potential senza sacrificare brand fit, execution feasibility o qualita percepita.\n"
            . "Se item_brain.trend_usage_mode e presente, trattalo come vincolo strategico: reactive_commentary solo se il segnale e davvero fresco, trend_safe_adaptation per tradurre il trend nel brand, format_acceleration per usare solo la grammatica del format senza inseguire il meme.\n"
            . "Se item_brain.trend_basis e presente, usa reason_why_now, reason_why_brand_fit ed expected_engagement_goal per decidere tono, hook e intensita del contenuto.\n"
            . "Se item_brain.professionality_guardrails e presente, rispettalo come policy forte: il contenuto non deve mai sembrare clickbait stupido o fuori posizionamento.\n"
            . "Se item_brain.overlay_brief e presente, il prompt immagine deve lasciare spazio compositivo pulito per un eventuale overlay tipografico breve, ma non deve chiedere testo lungo o lettering complesso renderizzato nel visual.\n"
            . "Se item_brain.continuity_brief e presente, trattalo come vincolo duro di continuita visiva.\n"
            . "La caption deve sembrare nativa per la piattaforma effettiva: hook iniziale, ritmo leggibile, taglio concreto e sintassi adatta a Instagram, Facebook, LinkedIn o TikTok.\n"
            . "Il brand descritto nel contesto e l azienda del cliente, non un caso studio di marketing o un cliente di agenzia.\n"
            . "Non usare strutture da consulenza tipo Contesto, Azione, Risultato o Framework, salvo richiesta esplicita del brief.\n"
            . "Non inventare metriche, percentuali, recensioni, risultati economici, prenotazioni, numeri o dati se non sono presenti nel contesto reale.\n"
            . "Per ristoranti, hospitality, retail e attivita locali scrivi come brand reale che parla al suo pubblico: esperienza, atmosfera, proposta, luogo, servizio, dettagli e invito naturale.\n"
            . "Il prompt immagine deve descrivere un visual da post social: stop-scroll, leggibile anche da miniatura, con gerarchia visiva forte e soggetto principale chiaro.\n"
            . "Se il formato e video, genera anche video_prompt: breve, chiaro, compatibile con generazione video, centrato su scene, movimento, ordine delle inquadrature, transizioni e fedelta dei riferimenti reali.\n"
            . "Se il formato e video, video_prompt NON deve includere istruzioni da immagine statica come 4:5 feed, miniatura, singolo frame hero o composizione da post fermo.\n"
            . "Se il formato e reel, genera anche reel_blueprint: un oggetto compatto per guidare un motore image-to-video.\n"
            . "reel_blueprint deve contenere: hook (string), anchor_frame (string), continuity_lock (string), visual_payoff (string), shots (array di 3 o 4 oggetti).\n"
            . "Ogni shot deve avere: order (numero), purpose (string), subject (string), camera (string), motion (string).\n"
            . "Per i reel evita storyboard complessi o troppe location nella stessa clip: meglio una progressione semplice e credibile.\n"
            . "Se il formato e video e il contesto riguarda wellness, beauty, massaggi, trattamenti corpo o una persona reale del brand, scrivi video_prompt in modo professionale e sobrio: evita linguaggio sensuale, parti del corpo enfatizzate, touch insistito, close-up ambigui o tono intimo.\n"
            . "Per wellness e beauty descrivi il servizio come trattamento professionale, atmosfera curata, gesto tecnico, accoglienza e relax, senza scivolare in contenuti che possano sembrare adult o sensuali.\n"
            . "Se il formato e video, genera anche voiceover: massimo 2-3 frasi brevi, parlabili, naturali, senza hashtag, senza elenco puntato, senza CTA aggressiva e senza leggere il prompt tecnico.\n"
            . "Mantieni coerenza con il feed del brand, ma evita l effetto foto corporate generica o immagine da catalogo non pensata per i social.\n"
            . "Rispetta repetition_rules: evita ripetizioni di hook, CTA e temi recenti.\n"
            . "Ogni post deve essere autosufficiente: comprensibile e utile anche da solo.\n"
            . "Mantieni comunque continuita strategica con campagne/serie quando presenti.\n"
            . "Ogni output deve essere distinto dai post recenti e dagli altri del piano.\n"
            . "Mantieni tono coerente con messaging_map tone_rules e regole do/dont.\n"
            . "Caption concreta, specifica e adatta alla piattaforma.\n"
            . "Usa item_brain.uniqueness_key come vincolo creativo anti-duplicato.\n"
            . "Il prompt immagine deve evitare loghi finti, watermark e testo tipografico lungo dentro il visual.\n"
            . "Per default il prompt immagine deve portare verso fotografia editoriale e commerciale fotorealistica, non verso illustrazione o look artificiale.\n"
            . "Se compaiono persone, devono sembrare reali: volti, occhi, denti, mani, pelle, postura e anatomia credibili.\n"
            . "Non usare parole come stilizzato, cartoon, CGI, render, 3D o illustrato, salvo richiesta esplicita del brief.\n"
            . "Se il contesto usa un luogo reale del brand, mantieni il luogo autentico e aggiungi eventuali persone in modo naturale.\n"
            . "Testo, CTA, hashtag e prompt immagine devono essere in italiano.\n"
            . "Restituisci SOLO JSON valido con chiavi:\n"
            . "- caption (string)\n"
            . "- hashtags (array of strings)\n"
            . "- cta (string)\n"
            . "- image_prompt (string)\n"
            . "- video_prompt (string)\n"
            . "- voiceover (string)\n"
            . "- reel_blueprint (object|null)\n"
            . "Niente markdown. Niente code fences. Nessun testo extra.";

        $input = [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => "Contesto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)],
        ];

        $url = $this->url('/v1/responses');

        try {
            $res = $this->request($timeout, true)
                ->retry(2, 300)
                ->post($url, [
                    'model' => $model,
                    'input' => $input,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'social_content_generation',
                            'strict' => true,
                            'schema' => $this->textGenerationSchema(),
                        ],
                    ],
                    'max_output_tokens' => $maxOutputTokens,
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI text error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();

            // Estrai testo dal payload Responses.
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
                'video_prompt' => $parsed['video_prompt'] ?? null,
                'voiceover' => $parsed['voiceover'] ?? null,
                'reel_blueprint' => is_array($parsed['reel_blueprint'] ?? null) ? $parsed['reel_blueprint'] : null,
                'response_id' => trim((string) ($data['id'] ?? '')) ?: null,
                'usage' => $this->extractUsage($data),
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
            $res = $this->request($timeout, true)
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
     * Image edit/variation partendo da una o piu immagini locali.
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
            $req = $this->request($timeout, false)
                ->retry(1, 400);

            foreach ($paths as $idx => $path) {
                $filename = basename($path);
                $mime = mime_content_type($path) ?: 'application/octet-stream';
                $req = $req->attach('image[]', file_get_contents($path), $filename, ['Content-Type' => $mime]);
                if ($idx >= 3) {
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

    /**
     * Genera voce (mp3) da testo tramite OpenAI TTS.
     */
    public function generateSpeechMp3(string $text, ?string $voiceOverride = null, ?string $modelOverride = null): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            throw new RuntimeException('Missing text for speech generation.');
        }

        $maxChars = (int) (config('openai.speech_max_chars') ?: 1000);
        $maxChars = max(200, min(4000, $maxChars));
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = trim(mb_substr($text, 0, $maxChars, 'UTF-8'));
        }

        $model = (string) ($modelOverride ?: config('openai.speech_model') ?: 'gpt-4o-mini-tts');
        $voice = (string) ($voiceOverride ?: config('openai.speech_voice') ?: 'alloy');
        $timeout = (int) (config('openai.timeout_speech') ?: config('openai.timeout') ?: 90);
        $url = $this->url('/v1/audio/speech');

        try {
            $res = $this->request($timeout, true)
                ->retry(1, 300)
                ->post($url, [
                    'model' => $model,
                    'voice' => $voice,
                    'input' => $text,
                    'response_format' => 'mp3',
                ]);

            if (!$res->successful()) {
                $body = $res->body();
                $body = is_string($body) ? Str::limit($body, 1200, '') : '';
                throw new RuntimeException("OpenAI speech error ({$res->status()}) URL={$url} BODY={$body}");
            }

            $bytes = $res->body();
            if (!is_string($bytes) || $bytes === '') {
                throw new RuntimeException('OpenAI speech empty response body.');
            }

            return $bytes;
        } catch (Throwable $e) {
            Log::warning('OpenAiService generateSpeechMp3 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'voice' => $voice,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Crea un job video (Sora).
     *
     * @param  array<string, mixed>  $options
     * @return array{id:string,raw:array}
     */
    public function createVideoJob(string $prompt, ?string $inputReferenceAbsolutePath = null, array $options = []): array
    {
        $model = strtolower(trim((string) ($options['model'] ?? config('openai.video_model') ?: 'sora-1.0-turbo')));
        if (!str_starts_with($model, 'sora-')) {
            $model = strtolower(trim((string) (config('openai.video_model') ?: 'sora-1.0-turbo')));
            if (!str_starts_with($model, 'sora-')) {
                $model = 'sora-1.0-turbo';
            }
        }
        $nSeconds = (int) ($options['seconds'] ?? config('openai.video_seconds') ?: 8);
        $nSeconds = max(1, $nSeconds);
        $size = (string) ($options['size'] ?? config('openai.video_size') ?: '720x1280');
        $timeout = (int) (config('openai.timeout_video_create') ?: config('openai.timeout') ?: 60);
        // OpenAI Sora API: https://api.openai.com/v1/video/generations
        $url = $this->url('/v1/video/generations');
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n_seconds' => $nSeconds,
            'size' => $size,
        ];

        try {
            if (is_string($inputReferenceAbsolutePath) && $inputReferenceAbsolutePath !== '' && is_file($inputReferenceAbsolutePath)) {
                // Image-to-video: primo frame come riferimento visivo
                $payload['frames'] = [
                    [
                        'image_url' => ['url' => $this->toVideoInputReferenceDataUri($inputReferenceAbsolutePath)],
                        'frame_position' => 'first',
                    ],
                ];
            }

            // Retry automatico su errori transitori (502/503/504 gateway/cloudflare)
            $res = null;
            $transientStatuses = [429, 502, 503, 504];
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if ($attempt > 0) {
                    sleep(5 * $attempt); // 5s, poi 10s
                }
                $res = $this->request($timeout, true)->post($url, $payload);
                if ($res->successful() || !in_array($res->status(), $transientStatuses)) {
                    break;
                }
                Log::info('OpenAiService createVideoJob transient error, retrying', [
                    'attempt' => $attempt + 1,
                    'status' => $res->status(),
                    'url' => $url,
                ]);
            }

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI video create error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            if (!is_array($data)) {
                throw new RuntimeException('Invalid video create response payload.');
            }

            $id = trim((string) ($data['id'] ?? ''));
            if ($id === '') {
                throw new RuntimeException('Missing video id in create response.');
            }

            return [
                'id' => $id,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('OpenAiService createVideoJob failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    private function toVideoInputReferenceDataUri(string $absolutePath): string
    {
        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        $mime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('OpenAI video input_reference must be a JPEG, PNG, or WEBP image.');
        }

        $bytes = @file_get_contents($absolutePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to read OpenAI video input reference image.');
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Recupera stato job video.
     */
    public function retrieveVideoJob(string $videoId): array
    {
        $videoId = trim($videoId);
        if ($videoId === '') {
            throw new RuntimeException('Missing video id.');
        }

        $timeout = (int) (config('openai.timeout_video_poll') ?: config('openai.timeout') ?: 60);
        $url = $this->url('/v1/video/generations/' . rawurlencode($videoId));
        $res = $this->request($timeout, true)->get($url);

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI video retrieve error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $data = $res->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid video retrieve response payload.');
        }

        return $data;
    }

    /**
     * Poll sincrono fino a completamento.
     */
    public function waitForVideoCompletion(string $videoId): array
    {
        $pollEvery = (int) (config('openai.video_poll_interval') ?: 10);
        $pollEvery = max(2, min(30, $pollEvery));
        $timeout = (int) (config('openai.video_poll_timeout') ?: 420);
        $timeout = max(30, $timeout);
        $deadline = microtime(true) + $timeout;
        $last = [];

        do {
            try {
                $last = $this->retrieveVideoJob($videoId);
            } catch (Throwable $e) {
                if ($this->isTransientVideoPollingError($e) && microtime(true) < $deadline) {
                    sleep($pollEvery);

                    continue;
                }

                throw $e;
            }
            $status = strtolower(trim((string) ($last['status'] ?? '')));

            if (in_array($status, ['completed', 'succeeded', 'done'], true)) {
                return $last;
            }

            if (in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                $reason = trim((string) data_get($last, 'error.message', data_get($last, 'last_error.message', 'video_generation_failed')));
                throw new RuntimeException("Video generation failed: {$reason}");
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Video generation timeout after {$timeout}s (status={$status})");
            }

            sleep($pollEvery);
        } while (true);
    }

    private function isTransientVideoPollingError(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));

        if ($message === '') {
            return false;
        }

        return str_contains($message, 'openai video retrieve error (500)')
            || str_contains($message, 'openai video retrieve error (502)')
            || str_contains($message, 'openai video retrieve error (503)')
            || str_contains($message, 'openai video retrieve error (504)')
            || str_contains($message, 'server_error')
            || str_contains($message, 'server error')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'gateway timeout')
            || str_contains($message, 'curl error')
            || str_contains($message, 'failed to connect');
    }

    /**
     * Scarica bytes video finali.
     */
    public function downloadVideoContent(string $videoId): string
    {
        return $this->downloadVideoVariant($videoId, 'content');
    }

    /**
     * Scarica thumbnail (immagine) del video.
     */
    public function downloadVideoThumbnail(string $videoId): string
    {
        return $this->downloadVideoVariant($videoId, 'thumbnail');
    }

    private function downloadVideoVariant(string $videoId, string $variant): string
    {
        $videoId = trim($videoId);
        if ($videoId === '') {
            throw new RuntimeException('Missing video id for download.');
        }

        $timeout = (int) (config('openai.timeout_video_download') ?: config('openai.timeout_images') ?: 120);

        // OpenAI Sora API: il video URL è nel campo data[0].url della generation completata.
        // Non esiste un endpoint separato /v1/video/generations/{id}/content.
        // Recuperiamo la generation per estrarre il CDN URL.
        $genUrl = $this->url('/v1/video/generations/' . rawurlencode($videoId));
        $genRes = $this->request((int) (config('openai.timeout_video_poll') ?: 60), true)->get($genUrl);
        if (!$genRes->successful()) {
            throw new RuntimeException("OpenAI video {$variant} retrieve error ({$genRes->status()}) URL={$genUrl} BODY=" . $genRes->body());
        }

        $gen = $genRes->json();
        if (!is_array($gen)) {
            throw new RuntimeException("OpenAI video {$variant} invalid generation payload.");
        }

        // Cerca URL nel campo data (array di video objects)
        $downloadUrl = '';
        $data = $gen['data'] ?? [];
        if (is_array($data) && !empty($data)) {
            $first = $data[0] ?? [];
            if (is_array($first)) {
                if ($variant === 'thumbnail') {
                    $downloadUrl = trim((string) ($first['thumbnail_url'] ?? $first['url'] ?? ''));
                } else {
                    $downloadUrl = trim((string) ($first['url'] ?? ''));
                }
            }
        }
        // Fallback: campo url diretto nella risposta
        if ($downloadUrl === '') {
            $downloadUrl = trim((string) ($gen['url'] ?? ''));
        }

        if ($downloadUrl === '') {
            throw new RuntimeException("OpenAI video {$variant} — URL di download non trovato nella generation response.");
        }

        $proxy = trim((string) (config('openai.proxy') ?: env('OPENAI_PROXY') ?: ''));
        $forceIpv4 = (bool) (config('openai.force_ipv4') ?: env('OPENAI_FORCE_IPV4') ?: false);
        $curlOptions = [];
        if ($proxy !== '') {
            $curlOptions['proxy'] = $proxy;
        }
        if ($forceIpv4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $curlOptions['curl'] = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        }

        $req = Http::timeout($timeout)->connectTimeout((int) (config('openai.connect_timeout') ?: 15));
        if (!empty($curlOptions)) {
            $req = $req->withOptions($curlOptions);
        }

        $res = $req->get($downloadUrl);
        if (!$res->successful()) {
            throw new RuntimeException("OpenAI video {$variant} download failed ({$res->status()}) URL={$downloadUrl}");
        }

        $body = $res->body();
        if (!is_string($body) || $body === '') {
            throw new RuntimeException("OpenAI video {$variant} empty body from CDN URL.");
        }

        return $body;
    }

    /**
     * Seleziona l'immagine brand piu coerente con il brief usando input multimodale.
     *
     * @param  array<int, array{path:string, original_name?:string, absolute_path:string}>  $images
     * @return array{path:string, confidence:float, reason:string}|null
     */
    public function selectBestBrandImageForBrief(string $brief, array $images): ?array
    {
        $brief = trim($brief);
        if ($brief === '') {
            return null;
        }

        $candidates = [];
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $path = trim((string) ($img['path'] ?? ''));
            $abs = trim((string) ($img['absolute_path'] ?? ''));
            if ($path === '' || $abs === '' || !is_file($abs)) {
                continue;
            }
            $dataUri = $this->toVisionDataUri($abs);
            if (!is_string($dataUri) || $dataUri === '') {
                continue;
            }

            $candidates[] = [
                'path' => $path,
                'name' => trim((string) ($img['original_name'] ?? '')),
                'data_uri' => $dataUri,
            ];
        }

        if (count($candidates) < 2) {
            return null;
        }

        $model = (string) (config('openai.vision_model') ?: env('OPENAI_VISION_MODEL') ?: config('openai.text_model') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);
        $url = $this->url('/v1/responses');

        $content = [
            [
                'type' => 'input_text',
                'text' => "Task: scegli una sola immagine tra i candidati in base al brief utente.\n"
                    . "Brief: {$brief}\n"
                    . "Regole: scegli l'immagine semanticamente piu coerente; se il brief cita 'ultima immagine/foto', preferisci il candidato 0.",
            ],
        ];

        foreach ($candidates as $idx => $candidate) {
            $content[] = [
                'type' => 'input_text',
                'text' => "CANDIDATO {$idx}\nNome file: " . ($candidate['name'] !== '' ? $candidate['name'] : '-') . "\nPath: " . $candidate['path'],
            ];
            $content[] = [
                'type' => 'input_image',
                'image_url' => $candidate['data_uri'],
            ];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'selected_index' => ['type' => 'integer'],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['selected_index', 'confidence', 'reason'],
            'additionalProperties' => false,
        ];

        try {
            $res = $this->request($timeout, true)
                ->retry(1, 300)
                ->post($url, [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => 'Sei un classificatore visivo. Rispondi solo con JSON valido conforme allo schema.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $content,
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'brand_image_selection',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI vision selection error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            $text = $this->extractResponsesText($data);
            $parsed = $this->safeJsonParse($text);
            if (!is_array($parsed)) {
                return null;
            }

            $idx = isset($parsed['selected_index']) ? (int) $parsed['selected_index'] : -1;
            if (!isset($candidates[$idx])) {
                return null;
            }

            $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;
            $confidence = max(0.0, min(1.0, $confidence));
            $reason = trim((string) ($parsed['reason'] ?? ''));

            return [
                'path' => (string) $candidates[$idx]['path'],
                'confidence' => $confidence,
                'reason' => $reason !== '' ? $reason : 'visual_semantic_match',
            ];
        } catch (Throwable $e) {
            Log::info('OpenAiService selectBestBrandImageForBrief skipped', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            return null;
        }
    }

    /**
     * Valida se un frame video include i soggetti delle immagini di riferimento.
     *
     * @param  array<int, string>  $referenceAbsolutePaths
     * @return array{all_present:bool,confidence:float,missing_indexes:array<int,int>,summary:string}|null
     */
    public function validateVideoFrameWithReferences(
        string $brief,
        string $frameAbsolutePath,
        array $referenceAbsolutePaths
    ): ?array {
        $frameUri = $this->toVisionDataUri($frameAbsolutePath);
        if (!is_string($frameUri) || $frameUri === '') {
            return null;
        }

        $refs = [];
        foreach (array_slice($referenceAbsolutePaths, 0, 4) as $idx => $abs) {
            if (!is_string($abs) || $abs === '' || !is_file($abs)) {
                continue;
            }
            $uri = $this->toVisionDataUri($abs);
            if (!is_string($uri) || $uri === '') {
                continue;
            }
            $refs[] = [
                'index' => $idx + 1,
                'data_uri' => $uri,
            ];
        }

        if (empty($refs)) {
            return null;
        }

        $model = (string) (config('openai.vision_model') ?: env('OPENAI_VISION_MODEL') ?: config('openai.text_model') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);
        $url = $this->url('/v1/responses');

        $brief = trim($brief);
        $content = [
            [
                'type' => 'input_text',
                'text' => "Task: verifica se il FRAME VIDEO contiene tutti i soggetti principali presenti nei riferimenti.\n"
                    . "Brief utente: " . ($brief !== '' ? $brief : '-')
                    . "\nRegole: sii severo. Se manca anche un soggetto chiave, all_present=false.",
            ],
            [
                'type' => 'input_text',
                'text' => 'FRAME VIDEO da validare:',
            ],
            [
                'type' => 'input_image',
                'image_url' => $frameUri,
            ],
        ];

        foreach ($refs as $ref) {
            $content[] = [
                'type' => 'input_text',
                'text' => 'RIFERIMENTO #' . (int) $ref['index'],
            ];
            $content[] = [
                'type' => 'input_image',
                'image_url' => $ref['data_uri'],
            ];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'all_present' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'missing_indexes' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['all_present', 'confidence', 'missing_indexes', 'summary'],
            'additionalProperties' => false,
        ];

        try {
            $res = $this->request($timeout, true)
                ->retry(1, 250)
                ->post($url, [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => 'Sei un validatore visivo rigoroso. Rispondi solo con JSON conforme allo schema.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $content,
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'video_reference_validation',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI video reference validation error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            $text = $this->extractResponsesText($data);
            $parsed = $this->safeJsonParse($text);
            if (!is_array($parsed)) {
                return null;
            }

            $allPresent = (bool) ($parsed['all_present'] ?? false);
            $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;
            $confidence = max(0.0, min(1.0, $confidence));
            $summary = trim((string) ($parsed['summary'] ?? ''));

            $missing = [];
            foreach ((array) ($parsed['missing_indexes'] ?? []) as $idx) {
                $n = (int) $idx;
                if ($n >= 1) {
                    $missing[] = $n;
                }
            }
            $missing = array_values(array_unique($missing));

            return [
                'all_present' => $allPresent,
                'confidence' => $confidence,
                'missing_indexes' => $missing,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            Log::info('OpenAiService validateVideoFrameWithReferences skipped', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            return null;
        }
    }

    /**
     * Valuta quanto una bozza testuale e visuale e allineata al brand e al brief.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function gradeGeneratedDraft(array $payload): ?array
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $generated = is_array($payload['generated'] ?? null) ? $payload['generated'] : [];
        $heuristic = is_array($payload['heuristic'] ?? null) ? $payload['heuristic'] : [];

        $model = (string) (config('openai.text_model') ?: env('OPENAI_TEXT_MODEL') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);
        $url = $this->url('/v1/responses');

        $reviewPacket = [
            'brand' => [
                'business_name' => (string) data_get($context, 'brand.business_name', ''),
                'industry' => (string) data_get($context, 'brand.industry', ''),
                'tone' => (string) data_get($context, 'brand.default_tone', data_get($context, 'brand.tone', '')),
                'cta' => (string) data_get($context, 'brand.cta', ''),
            ],
            'item' => [
                'platform' => (string) data_get($context, 'item.platform', ''),
                'format' => (string) data_get($context, 'item.format', ''),
                'objective' => (string) data_get($context, 'item_brain.objective', ''),
                'angle' => (string) data_get($context, 'item_brain.angle', ''),
                'image_direction' => (string) data_get($context, 'item_brain.image_direction', ''),
            ],
            'knowledge_pack' => [
                'positive_signals' => array_slice((array) data_get($context, 'knowledge_pack.feedback.positive_signals', []), 0, 6),
                'hard_avoid_rules' => array_slice((array) data_get($context, 'knowledge_pack.feedback.hard_avoid_rules', []), 0, 6),
                'preferred_formats' => array_slice((array) data_get($context, 'knowledge_pack.feedback.preferred_formats', []), 0, 4),
                'preferred_platforms' => array_slice((array) data_get($context, 'knowledge_pack.feedback.preferred_platforms', []), 0, 4),
                'themes' => array_slice((array) data_get($context, 'knowledge_pack.memory.themes', []), 0, 6),
                'offers' => array_slice((array) data_get($context, 'knowledge_pack.memory.offers', []), 0, 4),
            ],
            'examples' => array_slice((array) data_get($context, 'examples', []), 0, 4),
            'negative_examples' => array_slice((array) data_get($context, 'negative_examples', []), 0, 3),
            'generated' => [
                'caption' => (string) ($generated['caption'] ?? ''),
                'cta' => (string) ($generated['cta'] ?? ''),
                'image_prompt' => (string) ($generated['image_prompt'] ?? ''),
                'video_prompt' => (string) ($generated['video_prompt'] ?? ''),
            ],
            'heuristic' => $heuristic,
        ];

        $schema = [
            'type' => 'object',
            'properties' => [
                'overall_score' => ['type' => 'number'],
                'brand_alignment_score' => ['type' => 'number'],
                'brief_alignment_score' => ['type' => 'number'],
                'retry_recommended' => ['type' => 'boolean'],
                'summary' => ['type' => 'string'],
                'issues' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'strengths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['overall_score', 'brand_alignment_score', 'brief_alignment_score', 'retry_recommended', 'summary', 'issues', 'strengths'],
            'additionalProperties' => false,
        ];

        try {
            $res = $this->request($timeout, true)
                ->retry(1, 250)
                ->post($url, [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => 'Sei un revisore rigoroso di contenuti social branded. Valuta aderenza al brand, al brief e alle preferenze del cliente. Rispondi solo con JSON conforme allo schema.',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Valuta questa bozza di contenuto e prompt: ' . json_encode($reviewPacket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'content_alignment_review',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI content grading error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            $text = $this->extractResponsesText($data);
            $parsed = $this->safeJsonParse($text);
            if (!is_array($parsed)) {
                return null;
            }

            return [
                'overall_score' => max(0.0, min(1.0, (float) ($parsed['overall_score'] ?? 0.0))),
                'brand_alignment_score' => max(0.0, min(1.0, (float) ($parsed['brand_alignment_score'] ?? 0.0))),
                'brief_alignment_score' => max(0.0, min(1.0, (float) ($parsed['brief_alignment_score'] ?? 0.0))),
                'retry_recommended' => (bool) ($parsed['retry_recommended'] ?? false),
                'summary' => trim((string) ($parsed['summary'] ?? '')),
                'issues' => array_values(array_filter(array_map(fn ($value) => trim((string) $value), (array) ($parsed['issues'] ?? [])))),
                'strengths' => array_values(array_filter(array_map(fn ($value) => trim((string) $value), (array) ($parsed['strengths'] ?? [])))),
            ];
        } catch (Throwable $e) {
            Log::info('OpenAiService gradeGeneratedDraft skipped', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            return null;
        }
    }

    /**
     * Valida se un immagine generata mantiene i soggetti principali dei riferimenti.
     *
     * @param  array<int, string>  $referenceAbsolutePaths
     * @return array{all_present:bool,confidence:float,missing_indexes:array<int,int>,summary:string}|null
     */
    public function validateGeneratedImageWithReferences(
        string $brief,
        string $generatedAbsolutePath,
        array $referenceAbsolutePaths
    ): ?array {
        $generatedUri = $this->toVisionDataUri($generatedAbsolutePath);
        if (!is_string($generatedUri) || $generatedUri === '') {
            return null;
        }

        $refs = [];
        foreach (array_slice($referenceAbsolutePaths, 0, 4) as $idx => $abs) {
            if (!is_string($abs) || $abs === '' || !is_file($abs)) {
                continue;
            }
            $uri = $this->toVisionDataUri($abs);
            if (!is_string($uri) || $uri === '') {
                continue;
            }
            $refs[] = [
                'index' => $idx + 1,
                'data_uri' => $uri,
            ];
        }

        if (empty($refs)) {
            return null;
        }

        $model = (string) (config('openai.vision_model') ?: env('OPENAI_VISION_MODEL') ?: config('openai.text_model') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);
        $url = $this->url('/v1/responses');

        $brief = trim($brief);
        $content = [
            [
                'type' => 'input_text',
                'text' => "Task: verifica se l'immagine generata mantiene i soggetti chiave dei riferimenti ed e coerente con il brief.\n"
                    . 'Brief utente: ' . ($brief !== '' ? $brief : '-')
                    . "\nRegole: sii severo. Se manca un soggetto principale, all_present=false.",
            ],
            [
                'type' => 'input_text',
                'text' => 'IMMAGINE GENERATA da validare:',
            ],
            [
                'type' => 'input_image',
                'image_url' => $generatedUri,
            ],
        ];

        foreach ($refs as $ref) {
            $content[] = [
                'type' => 'input_text',
                'text' => 'RIFERIMENTO #' . (int) $ref['index'],
            ];
            $content[] = [
                'type' => 'input_image',
                'image_url' => $ref['data_uri'],
            ];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'all_present' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'missing_indexes' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['all_present', 'confidence', 'missing_indexes', 'summary'],
            'additionalProperties' => false,
        ];

        try {
            $res = $this->request($timeout, true)
                ->retry(1, 250)
                ->post($url, [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => 'Sei un validatore visivo rigoroso. Rispondi solo con JSON conforme allo schema.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $content,
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'image_reference_validation',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("OpenAI image reference validation error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            $text = $this->extractResponsesText($data);
            $parsed = $this->safeJsonParse($text);
            if (!is_array($parsed)) {
                return null;
            }

            $missing = [];
            foreach ((array) ($parsed['missing_indexes'] ?? []) as $idx) {
                $n = (int) $idx;
                if ($n >= 1) {
                    $missing[] = $n;
                }
            }

            return [
                'all_present' => (bool) ($parsed['all_present'] ?? false),
                'confidence' => max(0.0, min(1.0, (float) ($parsed['confidence'] ?? 0.0))),
                'missing_indexes' => array_values(array_unique($missing)),
                'summary' => trim((string) ($parsed['summary'] ?? '')),
            ];
        } catch (Throwable $e) {
            Log::info('OpenAiService validateGeneratedImageWithReferences skipped', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            return null;
        }
    }

    public function extractResponsesText(array $response): string
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
        $t = preg_replace('/^\xEF\xBB\xBF/', '', $t) ?? $t;

        if (str_starts_with($t, '```')) {
            $t = preg_replace('/^```[a-zA-Z]*\s*/', '', $t) ?? $t;
            $t = preg_replace('/\s*```$/', '', $t) ?? $t;
            $t = trim($t);
        }

        $decoded = json_decode($t, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;

        $balancedObject = $this->extractBalancedJsonObject($t);
        if ($balancedObject !== null) {
            $decoded2 = json_decode($balancedObject, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded2;
        }

        throw new RuntimeException('Risposta non JSON: ' . mb_substr($text, 0, 500));
    }

    protected function textGenerationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'caption' => ['type' => 'string'],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'cta' => ['type' => 'string'],
                'image_prompt' => ['type' => 'string'],
                'video_prompt' => ['type' => 'string'],
                'voiceover' => ['type' => 'string'],
                'reel_blueprint' => [
                    'anyOf' => [
                        ['type' => 'null'],
                        [
                            'type' => 'object',
                            'properties' => [
                                'hook' => ['type' => 'string'],
                                'anchor_frame' => ['type' => 'string'],
                                'continuity_lock' => ['type' => 'string'],
                                'visual_payoff' => ['type' => 'string'],
                                'shots' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'order' => ['type' => 'integer'],
                                            'purpose' => ['type' => 'string'],
                                            'subject' => ['type' => 'string'],
                                            'camera' => ['type' => 'string'],
                                            'motion' => ['type' => 'string'],
                                        ],
                                        'required' => ['order', 'purpose', 'subject', 'camera', 'motion'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => ['hook', 'anchor_frame', 'continuity_lock', 'visual_payoff', 'shots'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ],
            'required' => ['caption', 'hashtags', 'cta', 'image_prompt', 'video_prompt', 'voiceover', 'reel_blueprint'],
            'additionalProperties' => false,
        ];
    }

    protected function extractBalancedJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    protected function extractUsage(array $response): array
    {
        $usage = (array) ($response['usage'] ?? []);
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));

        return array_filter([
            'input_tokens' => $input > 0 ? $input : null,
            'output_tokens' => $output > 0 ? $output : null,
            'total_tokens' => $total > 0 ? $total : null,
        ], fn ($value) => $value !== null);
    }

    private function toVisionDataUri(string $absolutePath): ?string
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }

        $maxDim = 768;
        $image = $this->loadRasterImageForVision($absolutePath, $mime);
        if ($image === false) {
            $raw = @file_get_contents($absolutePath);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($image);
            return null;
        }

        $scale = min(1.0, $maxDim / max($srcW, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));
        $canvas = imagecreatetruecolor($dstW, $dstH);
        imagealphablending($canvas, true);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        imagejpeg($canvas, null, 82);
        $jpeg = ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($image);

        if (!is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private function loadRasterImageForVision(string $path, string $mime)
    {
        return match (strtolower($mime)) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALTER EGO — CONSISTENCY SCORE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Valuta quanto una caption aderisce al persona dell'alter ego.
     *
     * Ritorna array con: score (0-100), feedback, tone_score, vocabulary_score, style_score.
     * Usa un modello fast (gpt-4.1-mini) per tenere latenza e costo bassi.
     *
     * @return array<string, mixed>
     */
    public function scoreAlterEgoConsistency(string $caption, string $personaPrompt): array
    {
        $model   = 'gpt-4.1-mini';
        $timeout = 20;
        $url     = $this->url('/v1/responses');

        $instructions = "Sei un critico di content marketing specializzato in personal branding.\n"
            . "Ricevi la descrizione di una persona comunicativa (PERSONA) e una caption social (CAPTION).\n"
            . "Valuta quanto la caption rispecchia voce, tono, stile e temi della persona.\n"
            . "Restituisci SOLO JSON valido con:\n"
            . "- score (integer 0-100): aderenza complessiva\n"
            . "- tone_score (integer 0-100): aderenza del tono\n"
            . "- vocabulary_score (integer 0-100): aderenza del vocabolario\n"
            . "- style_score (integer 0-100): aderenza della struttura frasi\n"
            . "- feedback (string max 160 chars): una frase concisa su cosa funziona o cosa migliorare\n"
            . "Niente markdown. Niente code fences. Solo JSON.";

        $userMessage = "PERSONA:\n{$personaPrompt}\n\nCAPTION:\n{$caption}";

        $schema = [
            'type'       => 'json_schema',
            'json_schema' => [
                'name'   => 'alter_ego_consistency',
                'strict' => true,
                'schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'score'            => ['type' => 'integer'],
                        'tone_score'       => ['type' => 'integer'],
                        'vocabulary_score' => ['type' => 'integer'],
                        'style_score'      => ['type' => 'integer'],
                        'feedback'         => ['type' => 'string'],
                    ],
                    'required'             => ['score', 'tone_score', 'vocabulary_score', 'style_score', 'feedback'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        $res = $this->request($timeout, true)
            ->post($url, [
                'model' => $model,
                'input' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user',   'content' => $userMessage],
                ],
                'text' => ['format' => $schema],
            ]);

        if (!$res->successful()) {
            throw new RuntimeException("scoreAlterEgoConsistency HTTP {$res->status()}");
        }

        $text = $this->extractResponsesText($res->json() ?: []);
        $data = $this->safeJsonParse($text);

        if (!is_array($data)) {
            return [];
        }

        return [
            'score'            => max(0, min(100, (int) ($data['score']            ?? 0))),
            'tone_score'       => max(0, min(100, (int) ($data['tone_score']       ?? 0))),
            'vocabulary_score' => max(0, min(100, (int) ($data['vocabulary_score'] ?? 0))),
            'style_score'      => max(0, min(100, (int) ($data['style_score']      ?? 0))),
            'feedback'         => Str::limit(trim((string) ($data['feedback'] ?? '')), 200, ''),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALTER EGO — VOICE EXTRACTION FROM SAMPLES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Analizza testi campione e restituisce un profilo di voce strutturato.
     * Usato dal wizard "Analizza la mia voce" per pre-compilare i campi alter ego.
     *
     * @param  array<int, string>  $samples
     * @return array<string, mixed>
     */
    public function extractVoiceFromSamples(array $samples): array
    {
        $samples = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            $samples
        ), fn ($s) => $s !== ''));

        if (empty($samples)) {
            return [];
        }

        $model   = 'gpt-4.1-mini';
        $timeout = 30;
        $url     = $this->url('/v1/responses');

        $instructions = "Sei un esperto di personal branding e analisi del tono comunicativo.\n"
            . "Analizza i testi forniti e identifica la voce narrativa dell'autore.\n"
            . "Restituisci SOLO JSON valido con:\n"
            . "- tone (string): uno tra autorevole | diretto | empatico | ironico | formale | colloquiale\n"
            . "- sentence_style (string): uno tra brevi_incisive | narrative | domande_retoriche\n"
            . "- vocabulary_level (string): uno tra tecnico | accessibile | misto\n"
            . "- signature_phrases (array of string): max 5 frasi o costruzioni tipiche dell'autore\n"
            . "- topics_owned (array of string): max 8 temi ricorrenti su cui l'autore è autorevole\n"
            . "- unique_perspective (string max 250 chars): cosa rende unico questo comunicatore\n"
            . "- audience_role (string): uno tra teacher | peer | mentor | challenger | entertainer\n"
            . "Niente markdown. Niente code fences. Solo JSON.";

        $combinedText = implode("\n\n---\n\n", $samples);
        $userMessage  = "TESTI DA ANALIZZARE:\n\n{$combinedText}";

        $schema = [
            'type'       => 'json_schema',
            'json_schema' => [
                'name'   => 'alter_ego_voice_profile',
                'strict' => true,
                'schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tone'               => ['type' => 'string'],
                        'sentence_style'     => ['type' => 'string'],
                        'vocabulary_level'   => ['type' => 'string'],
                        'signature_phrases'  => ['type' => 'array', 'items' => ['type' => 'string']],
                        'topics_owned'       => ['type' => 'array', 'items' => ['type' => 'string']],
                        'unique_perspective' => ['type' => 'string'],
                        'audience_role'      => ['type' => 'string'],
                    ],
                    'required' => [
                        'tone', 'sentence_style', 'vocabulary_level',
                        'signature_phrases', 'topics_owned',
                        'unique_perspective', 'audience_role',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];

        $res = $this->request($timeout, true)
            ->post($url, [
                'model' => $model,
                'input' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user',   'content' => $userMessage],
                ],
                'text' => ['format' => $schema],
            ]);

        if (!$res->successful()) {
            throw new RuntimeException("extractVoiceFromSamples HTTP {$res->status()}");
        }

        $text = $this->extractResponsesText($res->json() ?: []);
        $data = $this->safeJsonParse($text);

        if (!is_array($data)) {
            return [];
        }

        $validTones    = ['autorevole', 'diretto', 'empatico', 'ironico', 'formale', 'colloquiale'];
        $validStyles   = ['brevi_incisive', 'narrative', 'domande_retoriche'];
        $validVocab    = ['tecnico', 'accessibile', 'misto'];
        $validAudience = ['teacher', 'peer', 'mentor', 'challenger', 'entertainer'];

        return [
            'tone'               => in_array($data['tone'] ?? '', $validTones, true) ? $data['tone'] : '',
            'sentence_style'     => in_array($data['sentence_style'] ?? '', $validStyles, true) ? $data['sentence_style'] : '',
            'vocabulary_level'   => in_array($data['vocabulary_level'] ?? '', $validVocab, true) ? $data['vocabulary_level'] : 'misto',
            'signature_phrases'  => array_values(array_filter(array_map('strval', (array) ($data['signature_phrases'] ?? [])))),
            'topics_owned'       => array_values(array_filter(array_map('strval', (array) ($data['topics_owned'] ?? [])))),
            'unique_perspective' => Str::limit(trim((string) ($data['unique_perspective'] ?? '')), 800, ''),
            'audience_role'      => in_array($data['audience_role'] ?? '', $validAudience, true) ? $data['audience_role'] : '',
        ];
    }
}
