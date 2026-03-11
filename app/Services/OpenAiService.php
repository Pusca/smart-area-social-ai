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
        $model = (string) (config('openai.text_model') ?: env('OPENAI_TEXT_MODEL') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);

        $instructions =
            "Sei una social media manager senior.\n"
            . "Usa strategia, profilo brand e direttive item_brain quando presenti nel contesto.\n"
            . "Usa memory_summary.feedback_summary come memoria del gusto del tenant: cio che piace va riutilizzato con intelligenza, cio che non piace va evitato.\n"
            . "Tratta memory_summary.hard_avoid_rules e feedback_loop.tenant_feedback.recent_objections come vincoli forti.\n"
            . "Se feedback_loop.active_request e presente, e una correzione prioritaria da applicare subito nella rigenerazione corrente.\n"
            . "Se asset_variables contiene una persona guidata o un persona pack, trattala come identita reale da preservare: stesso volto, stessi lineamenti, stessa eta apparente e tratti distintivi coerenti tra contenuti diversi.\n"
            . "Quando una persona guidata ha immutable_traits, role, look_notes o prompt_notes, usali come istruzioni operative forti per caption, image_prompt e video_prompt.\n"
            . "Quando la correzione riguarda il visual, migliora soprattutto prompt immagine e coerenza visuale, preservando il resto se gia valido.\n"
            . "Quando la correzione riguarda copy o tono, migliora soprattutto caption, CTA e angolo narrativo, preservando il visual se gia coerente.\n"
            . "Tutto cio che generi e pensato per post social: non brochure, non sito corporate, non stock generico.\n"
            . "Usa social_publication_context per capire ruolo del contenuto nel feed, nella serie e nell insieme delle pubblicazioni.\n"
            . "La caption deve sembrare nativa per Instagram/Facebook: hook iniziale, ritmo leggibile, taglio concreto e orientato al comportamento social.\n"
            . "Il brand descritto nel contesto e l azienda del cliente, non un caso studio di marketing o un cliente di agenzia.\n"
            . "Non usare strutture da consulenza tipo Contesto, Azione, Risultato o Framework, salvo richiesta esplicita del brief.\n"
            . "Non inventare metriche, percentuali, recensioni, risultati economici, prenotazioni, numeri o dati se non sono presenti nel contesto reale.\n"
            . "Per ristoranti, hospitality, retail e attivita locali scrivi come brand reale che parla al suo pubblico: esperienza, atmosfera, proposta, luogo, servizio, dettagli e invito naturale.\n"
            . "Il prompt immagine deve descrivere un visual da post social: stop-scroll, leggibile anche da miniatura, con gerarchia visiva forte e soggetto principale chiaro.\n"
            . "Se il formato e video, genera anche video_prompt: breve, chiaro, compatibile con generazione video, centrato su scene, movimento, ordine delle inquadrature, transizioni e fedelta dei riferimenti reali.\n"
            . "Se il formato e video, video_prompt NON deve includere istruzioni da immagine statica come 4:5 feed, miniatura, singolo frame hero o composizione da post fermo.\n"
            . "Se il formato e video, genera anche voiceover: massimo 2-3 frasi brevi, parlabili, naturali, senza hashtag, senza elenco puntato, senza CTA aggressiva e senza leggere il prompt tecnico.\n"
            . "Mantieni coerenza con il feed del brand, ma evita l effetto foto corporate generica o immagine da catalogo non pensata per i social.\n"
            . "Rispetta repetition_rules: evita ripetizioni di hook, CTA e temi recenti.\n"
            . "Ogni post deve essere autosufficiente: comprensibile e utile anche da solo.\n"
            . "Mantieni comunque continuita strategica con campagne/serie quando presenti.\n"
            . "Ogni output deve essere distinto dai post recenti e dagli altri del piano.\n"
            . "Mantieni tono coerente con messaging_map tone_rules e regole do/dont.\n"
            . "Caption concreta, specifica e adatta alla piattaforma.\n"
            . "Usa item_brain.uniqueness_key come vincolo creativo anti-duplicato.\n"
            . "Il prompt immagine deve evitare loghi finti, watermark e testo sovraimpresso.\n"
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
            . "Niente markdown. Niente code fences. Nessun testo extra.";

        $input = [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => "Contesto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];

        $url = $this->url('/v1/responses');

        try {
            $res = $this->request($timeout, true)
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
                'video_prompt' => $parsed['video_prompt'] ?? null,
                'voiceover' => $parsed['voiceover'] ?? null,
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
        $model = (string) ($options['model'] ?? config('openai.video_model') ?: 'sora-2');
        $seconds = (string) ($options['seconds'] ?? config('openai.video_seconds') ?: '8');
        $size = (string) ($options['size'] ?? config('openai.video_size') ?: '720x1280');
        $timeout = (int) (config('openai.timeout_video_create') ?: config('openai.timeout') ?: 60);
        $url = $this->url('/v1/videos');

        try {
            if (is_string($inputReferenceAbsolutePath) && $inputReferenceAbsolutePath !== '' && is_file($inputReferenceAbsolutePath)) {
                $mime = mime_content_type($inputReferenceAbsolutePath) ?: 'application/octet-stream';
                $content = file_get_contents($inputReferenceAbsolutePath);
                if (!is_string($content) || $content === '') {
                    throw new RuntimeException('Unable to read input reference image for video generation.');
                }

                $res = $this->request($timeout, false)
                    ->retry(1, 400)
                    ->attach('input_reference', $content, basename($inputReferenceAbsolutePath), ['Content-Type' => $mime])
                    ->post($url, [
                        'model' => $model,
                        'prompt' => $prompt,
                        'seconds' => $seconds,
                        'size' => $size,
                    ]);
            } else {
                $res = $this->request($timeout, true)
                    ->retry(1, 400)
                    ->post($url, [
                        'model' => $model,
                        'prompt' => $prompt,
                        'seconds' => $seconds,
                        'size' => $size,
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
        $url = $this->url('/v1/videos/' . rawurlencode($videoId));
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
            $last = $this->retrieveVideoJob($videoId);
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
        $url = $this->url('/v1/videos/' . rawurlencode($videoId) . '/' . rawurlencode($variant));
        $req = Http::withToken($this->apiKey())
            ->timeout($timeout)
            ->connectTimeout((int) (config('openai.connect_timeout') ?: 15));

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

        $res = $req->get($url);

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI video {$variant} download error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $contentType = strtolower((string) ($res->header('Content-Type') ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $data = $res->json();
            if (is_array($data)) {
                $downloadUrl = trim((string) ($data['url'] ?? ''));
                if ($downloadUrl !== '') {
                    $down = Http::timeout($timeout)->get($downloadUrl);
                    if (!$down->successful()) {
                        throw new RuntimeException("OpenAI video {$variant} download url failed ({$down->status()}) URL={$downloadUrl}");
                    }
                    $body2 = $down->body();
                    if ($body2 === '') {
                        throw new RuntimeException("OpenAI video {$variant} empty body from download url.");
                    }
                    return $body2;
                }
            }
            throw new RuntimeException("OpenAI video {$variant} returned JSON without downloadable url.");
        }

        $body = $res->body();
        if (!is_string($body) || $body === '') {
            throw new RuntimeException("OpenAI video {$variant} empty body.");
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
}
