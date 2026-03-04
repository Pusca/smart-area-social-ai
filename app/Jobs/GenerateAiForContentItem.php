<?php

namespace App\Jobs;

use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateAiForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $item = ContentItem::query()->with('plan')->findOrFail($this->contentItemId);

        $item->ai_status = 'pending';
        $item->ai_error = null;
        $item->save();

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $liveBrandAssets = $this->loadBrandAssetsFromDb((int) $item->tenant_id);
        $meta['brand_assets'] = $this->mergeBrandAssets((array) data_get($meta, 'brand_assets', []), $liveBrandAssets);
        $strategy = data_get($meta, 'strategy', $item->plan?->strategy ?? []);
        $itemBrain = data_get($meta, 'item_brain', []);
        $tenantProfile = data_get($meta, 'tenant_profile', data_get($meta, 'brand', []));
        $memorySummary = data_get($meta, 'memory_summary', []);

        if ($this->isDemoMode()) {
            $this->applyDemoPreset($item, $tenantProfile, $itemBrain, $meta);
            return;
        }

        $recentCaptions = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_caption')
            ->whereIn('ai_status', ['done', 'pending'])
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('ai_caption')
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        $planContext = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get(['title', 'ai_caption']);

        $planTitles = $planContext
            ->pluck('title')
            ->filter()
            ->map(fn ($title) => Str::limit((string) $title, 120, ''))
            ->values()
            ->all();

        $planCaptions = $planContext
            ->pluck('ai_caption')
            ->filter()
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        try {
            $context = [
                'brand' => $tenantProfile,
                'plan' => data_get($meta, 'plan', []),
                'strategy' => $strategy,
                'item_brain' => $itemBrain,
                'memory_summary' => $memorySummary,
                'repetition_rules' => [
                    'avoid_list' => array_values(array_unique(array_filter(array_merge(
                        (array) data_get($itemBrain, 'avoid_list', []),
                        (array) data_get($strategy, 'repetition_guard.avoid_terms', []),
                        (array) data_get($memorySummary, 'avoid_repetition', [])
                    )))),
                    'recent_captions' => $recentCaptions,
                    'plan_titles' => $planTitles,
                    'plan_captions' => $planCaptions,
                ],
                'item' => [
                    'platform' => $item->platform,
                    'format' => $item->format,
                    'title' => $item->title,
                    'scheduled_at' => optional($item->scheduled_at)->toDateTimeString(),
                ],
            ];

            $comparisonTexts = array_values(array_unique(array_filter(array_merge(
                $recentCaptions,
                $planCaptions,
                $planTitles
            ))));

            $bestGen = null;
            $bestScore = 1.0;
            $similarityFeedback = null;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $iterContext = $context;
                if ($similarityFeedback !== null) {
                    $iterContext['generation_guard'] = [
                        'retry' => $attempt + 1,
                        'reason' => 'La caption precedente era troppo simile a contenuti esistenti.',
                        'most_similar_caption' => $similarityFeedback['text'],
                        'similarity_score' => $similarityFeedback['score'],
                        'instruction' => 'Crea hook, angolo narrativo e CTA chiaramente diversi, restando coerente con la strategia.',
                    ];
                }

                $gen = $openAi->generateContent($iterContext);
                $caption = trim((string) ($gen['caption'] ?? ''));
                $score = $this->maxTextSimilarity($caption, $comparisonTexts);

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestGen = $gen;
                }

                if ($score < 0.72) {
                    $bestGen = $gen;
                    break;
                }

                $similarityFeedback = [
                    'score' => $score,
                    'text' => $this->closestText($caption, $comparisonTexts),
                ];
            }

            $gen = $bestGen ?? [];

            $item->ai_caption = $gen['caption'] ?? $item->ai_caption;
            $item->ai_hashtags = $gen['hashtags'] ?? [];
            $item->ai_cta = $gen['cta'] ?? ($itemBrain['cta'] ?? $item->ai_cta);
            $item->ai_image_prompt = $gen['image_prompt'] ?? $item->ai_image_prompt;
            $item->ai_meta = array_merge($meta, [
                'text_similarity_score' => round($bestScore, 4),
                'text_uniqueness_checked_at' => now()->toDateTimeString(),
            ]);
            $item->save();
        } catch (Throwable $e) {
            if ($this->isQuotaOrRateLimitError($e) || $this->isTransientNetworkError($e)) {
                $reason = $this->isQuotaOrRateLimitError($e)
                    ? 'OpenAI quota/rate-limit'
                    : 'OpenAI rete/DNS non raggiungibile';

                $fallback = $this->fallbackText($item, $tenantProfile, $itemBrain);
                $item->ai_caption = $fallback['caption'];
                $item->ai_hashtags = $fallback['hashtags'];
                $item->ai_cta = $fallback['cta'];
                $item->ai_image_prompt = $fallback['image_prompt'];
                $item->ai_error = 'TEXT fallback: ' . $reason;
                $item->ai_meta = array_merge($meta, [
                    'text_fallback' => true,
                    'text_fallback_reason' => $reason,
                    'text_fallback_at' => now()->toDateTimeString(),
                ]);
                $item->save();
            } else {
                $item->ai_status = 'error';
                $item->ai_error = 'TEXT: ' . $e->getMessage();
                $item->save();

                Log::error('GenerateAiForContentItem text failed', [
                    'content_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        try {
            $prompt = trim((string) ($item->ai_image_prompt ?? ''));
            $brandImageSources = $this->resolveBrandImageSources($strategy, $meta, (int) $item->tenant_id);
            $brandDecision = $this->decideBrandImageUsage($item, $brandImageSources, $openAi);
            $selectedBrandImage = $brandDecision['path'] ?? null;
            $selectedBrandImagePaths = array_values(array_filter(array_map(
                'strval',
                (array) ($brandDecision['paths'] ?? ($selectedBrandImage ? [$selectedBrandImage] : []))
            )));
            if (is_string($selectedBrandImage) && $selectedBrandImage !== '' && !in_array($selectedBrandImage, $selectedBrandImagePaths, true)) {
                array_unshift($selectedBrandImagePaths, $selectedBrandImage);
            }
            $selectedBrandImagePaths = array_values(array_unique($selectedBrandImagePaths));

            if ($prompt === '') {
                $brandName = data_get($tenantProfile, 'business_name', 'Brand');
                $industry = data_get($tenantProfile, 'industry', '');
                $palette = data_get($strategy, 'brand_references.palette', '');
                $logoPath = data_get($strategy, 'brand_references.logo_path', '');
                $visualRules = data_get($itemBrain, 'image_direction', 'Visual coerente con il brand.');

                $prompt = "Crea un'immagine social quadrata per {$brandName}. "
                    . "Settore: {$industry}. "
                    . "Direzione visiva: {$visualRules}. "
                    . "Palette colore suggerita: {$palette}. "
                    . "Percorso logo di riferimento (solo contesto stilistico): {$logoPath}. "
                    . ($selectedBrandImage ? "Parti dall'immagine brand fornita e adattala creativamente a questa strategia di post. " : "Crea la composizione da zero seguendo la strategia e mantenendo novita rispetto ai post precedenti. ")
                    . "Non generare loghi finti, nome brand scritto, watermark o testo sovraimpresso nell'immagine. "
                    . "Se è necessario includere testo grafico nell'immagine, usa solo italiano corretto. "
                    . "Stile professionale, coerente con il brand e totalmente in italiano.";

                $item->ai_image_prompt = $prompt;
                $item->save();
            }

            $recentImageHashes = $this->loadRecentImageHashes($item->tenant_id, $item->id, 24);
            $bytes = null;
            $finalHash = null;
            $imageSourceMode = 'text_to_image';
            $brandSourceUsed = null;
            $brandSourcesUsed = [];
            $imageSourceFallback = null;
            $publicDisk = Storage::disk('public');
            $selectedBrandImageAbsList = [];
            foreach ($selectedBrandImagePaths as $brandPath) {
                if (!is_string($brandPath) || $brandPath === '' || !$publicDisk->exists($brandPath)) {
                    continue;
                }
                $selectedBrandImageAbsList[] = $publicDisk->path($brandPath);
            }
            $selectedBrandImageAbs = $selectedBrandImageAbsList[0] ?? null;
            $logoRuntime = $this->resolveLogoRuntime($item, $strategy, $meta, $selectedBrandImageAbs);
            $logoSceneAbs = isset($logoRuntime['abs']) && is_string($logoRuntime['abs']) ? $logoRuntime['abs'] : null;
            $logoScenePath = isset($logoRuntime['path']) && is_string($logoRuntime['path']) ? $logoRuntime['path'] : null;
            $logoRequested = (bool) ($logoRuntime['requested'] ?? false);
            $logoMode = (string) ($logoRuntime['mode'] ?? 'scene');
            $embedLogoInScene = $logoRequested
                ? (bool) $logoSceneAbs
                : $this->shouldEmbedLogoInScene($item, $selectedBrandImageAbs, $logoSceneAbs);

            $isVideoFormat = $this->isVideoFormat($item);
            if ($isVideoFormat) {
                $videoResult = $this->generateVideoAsset(
                    openAi: $openAi,
                    item: $item,
                    prompt: $prompt,
                    selectedBrandImageAbs: $selectedBrandImageAbs,
                    selectedBrandImageAbsList: $selectedBrandImageAbsList,
                    selectedBrandImagePath: $selectedBrandImage,
                    selectedBrandImagePaths: $selectedBrandImagePaths,
                    logoRuntime: $logoRuntime,
                    brandDecision: $brandDecision
                );

                $videoPath = trim((string) ($videoResult['video_path'] ?? ''));
                $thumbPath = trim((string) ($videoResult['thumbnail_path'] ?? ''));

                if ($videoPath !== '') {
                    $gridPreviewPath = $this->createLocalImagePlaceholder($item, $tenantProfile);
                    if (is_string($gridPreviewPath) && trim($gridPreviewPath) !== '') {
                        $item->ai_image_path = $gridPreviewPath;
                    } elseif ($thumbPath !== '') {
                        $item->ai_image_path = $thumbPath;
                    }

                    $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
                    $metaNow['video_generation'] = [
                        'source' => (string) ($videoResult['source'] ?? 'sora_video'),
                        'video_id' => (string) ($videoResult['video_id'] ?? ''),
                        'video_path' => $videoPath,
                        'thumbnail_path' => $thumbPath,
                        'grid_preview_path' => (string) ($item->ai_image_path ?? ''),
                        'reference_path' => (string) ($videoResult['reference_path'] ?? ''),
                        'reference_paths' => (array) ($videoResult['reference_paths'] ?? []),
                        'reference_reason' => (string) ($videoResult['reference_reason'] ?? ''),
                        'brand_source_paths' => $selectedBrandImagePaths,
                        'logo_requested' => $logoRequested,
                        'logo_source_path' => $logoScenePath,
                        'logo_mode' => $logoMode,
                        'logo_variant' => (string) ($logoRuntime['variant'] ?? 'auto'),
                        'logo_selection_reason' => (string) ($logoRuntime['reason'] ?? ''),
                        'brand_selection' => $brandDecision,
                        'reference_validation' => $videoResult['reference_validation'] ?? null,
                        'composition_reference' => $videoResult['composition_reference'] ?? null,
                        'generation_attempts' => (int) ($videoResult['generation_attempts'] ?? 1),
                        'fallback' => $imageSourceFallback,
                        'generated_at' => now()->toDateTimeString(),
                    ];
                    $item->ai_meta = $metaNow;

                    $assets = is_array($item->assets) ? $item->assets : [];
                    foreach ($selectedBrandImagePaths as $brandPath) {
                        $assets[] = ['type' => 'brand_source', 'path' => $brandPath];
                    }
                    if ($logoRequested && $logoScenePath) {
                        $assets[] = ['type' => 'brand_logo', 'path' => $logoScenePath];
                    }
                    $assets[] = ['type' => 'ai_generated_video', 'path' => $videoPath];
                    if ($thumbPath !== '') {
                        $assets[] = ['type' => 'ai_generated_thumbnail', 'path' => $thumbPath];
                    }
                    $item->assets = $this->uniqueAssets($assets);
                    $item->save();
                }
            } else {
                for ($imgAttempt = 0; $imgAttempt < 2; $imgAttempt++) {
                    $attemptPrompt = $prompt;
                    if ($imgAttempt > 0) {
                        $attemptPrompt .= ' Crea una composizione visibilmente diversa dai post brand precedenti (nuovo layout, inquadratura e gerarchia visiva).';
                    }
                    $attemptPrompt .= ' Se compaiono scritte visibili nell immagine, devono essere in italiano naturale e corretto.';

                    if (!empty($selectedBrandImageAbsList) || ($logoRequested && $logoSceneAbs)) {
                        try {
                            $editPaths = [];
                            foreach (array_slice($selectedBrandImageAbsList, 0, 4) as $referenceAbs) {
                                if (is_string($referenceAbs) && $referenceAbs !== '') {
                                    $editPaths[] = $referenceAbs;
                                }
                            }
                            if ($embedLogoInScene && $logoSceneAbs && count($editPaths) < 4) {
                                $editPaths[] = $logoSceneAbs;
                            }
                            if (empty($editPaths) && $logoSceneAbs) {
                                $editPaths[] = $logoSceneAbs;
                            }

                            if ($logoRequested && $logoSceneAbs) {
                                if ($logoMode === 'background') {
                                    $attemptPrompt .= ' Usa esclusivamente il logo reale fornito come immagine di input. ';
                                    $attemptPrompt .= 'Posizionalo nello sfondo/dietro i soggetti come watermark grande e semi-trasparente, senza deformarlo. ';
                                    $attemptPrompt .= 'NON sostituire il logo con testo: non scrivere mai il nome dell azienda nell immagine.';
                                } else {
                                    $attemptPrompt .= ' Inserisci il logo reale fornito in modo naturale nella scena (es. supporto fisico, insegna, packaging), senza distorcerlo. ';
                                    $attemptPrompt .= 'NON sostituire il logo con testo: non scrivere mai il nome dell azienda nell immagine.';
                                }
                            } else {
                                $attemptPrompt .= ' Non aggiungere loghi o testo brand generati dal modello.';
                            }

                            if (!empty($selectedBrandImageAbsList)) {
                                if (count($selectedBrandImageAbsList) > 1) {
                                    $attemptPrompt .= ' Combina in modo coerente i riferimenti visual multipli forniti (volto, oggetti, ambientazione), mantenendo identita e soggetti riconoscibili.';
                                } else {
                                    $attemptPrompt .= ' Mantieni il DNA visivo riconoscibile dell immagine brand fornita (scena, oggetti, inquadratura) adattandola alla strategia del post.';
                                }
                            } else {
                                $attemptPrompt .= ' Crea una scena completa da zero, coerente con il brief e con il brand.';
                            }

                            $img = $openAi->generateImageEditBase64($attemptPrompt, $editPaths);
                            if (!empty($selectedBrandImageAbsList)) {
                                $imageSourceMode = count($selectedBrandImageAbsList) > 1 ? 'brand_multi_image_edit' : 'brand_image_edit';
                                $brandSourcesUsed = array_values(array_slice($selectedBrandImagePaths, 0, 4));
                                $brandSourceUsed = $brandSourcesUsed[0] ?? null;
                            } else {
                                $imageSourceMode = 'logo_guided_edit';
                                $brandSourcesUsed = [];
                                $brandSourceUsed = null;
                            }
                        } catch (Throwable $editError) {
                            $imageSourceFallback = 'edit_failed_fallback_to_text_to_image';
                            $img = $openAi->generateImageBase64($attemptPrompt);
                            $imageSourceMode = 'text_to_image';
                            $brandSourcesUsed = [];
                            $brandSourceUsed = null;
                            $metaFallback = is_array($item->ai_meta) ? $item->ai_meta : [];
                            $metaFallback['image_edit_error'] = Str::limit($editError->getMessage(), 240, '');
                            $metaFallback['image_edit_error_at'] = now()->toDateTimeString();
                            $item->ai_meta = $metaFallback;
                            $item->save();
                        }
                    } else {
                        $img = $openAi->generateImageBase64($attemptPrompt);
                        $imageSourceMode = 'text_to_image';
                        $brandSourcesUsed = [];
                        $brandSourceUsed = null;
                    }
                    $candidateBytes = base64_decode((string) ($img['b64'] ?? ''), true);

                    if ($candidateBytes === false || $candidateBytes === '') {
                        continue;
                    }

                    $candidateHash = $this->computeImageHashFromBytes($candidateBytes);
                    if ($candidateHash === null) {
                        $bytes = $candidateBytes;
                        $finalHash = null;
                        break;
                    }

                    $similarity = $this->maxImageHashSimilarity($candidateHash, $recentImageHashes);
                    if ($similarity < 0.9 || $imgAttempt === 1) {
                        $bytes = $candidateBytes;
                        $finalHash = $candidateHash;
                        break;
                    }
                }

                if (is_string($bytes) && $bytes !== '') {
                    $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
                    Storage::disk('public')->put($filename, $bytes);
                    $item->ai_image_path = $filename;
                    $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
                    $metaNow['image_generation'] = [
                        'source' => $imageSourceMode,
                        'brand_source_path' => $brandSourceUsed,
                        'brand_source_paths' => $brandSourcesUsed,
                        'logo_requested' => $logoRequested,
                        'logo_in_scene' => in_array($imageSourceMode, ['brand_image_edit', 'brand_multi_image_edit', 'logo_guided_edit'], true) ? $embedLogoInScene : false,
                        'logo_source_path' => $logoScenePath,
                        'logo_mode' => $logoMode,
                        'logo_variant' => (string) ($logoRuntime['variant'] ?? 'auto'),
                        'logo_selection_reason' => (string) ($logoRuntime['reason'] ?? ''),
                        'brand_selection' => $brandDecision,
                        'fallback' => $imageSourceFallback,
                        'image_hash' => $finalHash,
                        'generated_at' => now()->toDateTimeString(),
                    ];
                    if ($logoRequested && $logoScenePath && ($logoMode === 'background' || $imageSourceMode === 'text_to_image')) {
                        $overlayMeta = $metaNow;
                        $overlayMeta['logo_runtime'] = [
                            'force' => true,
                            'path' => $logoScenePath,
                            'mode' => $logoMode === 'background' ? 'background' : 'corner',
                        ];
                        $overlay = $this->applyBrandLogoOverlay($item, $strategy, $overlayMeta);
                        $metaNow['image_generation']['logo_overlay'] = $overlay;
                    }
                    $item->ai_meta = $metaNow;

                    $assets = is_array($item->assets) ? $item->assets : [];
                    foreach ($brandSourcesUsed as $brandPath) {
                        $assets[] = ['type' => 'brand_source', 'path' => $brandPath];
                    }
                    if ($logoRequested && $logoScenePath) {
                        $assets[] = ['type' => 'brand_logo', 'path' => $logoScenePath];
                    }
                    $assets[] = ['type' => 'ai_generated', 'path' => $filename];
                    $item->assets = $this->uniqueAssets($assets);
                    $item->save();
                }
            }
        } catch (Throwable $e) {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $meta['image_error'] = $e->getMessage();
            $meta['image_error_at'] = now()->toDateTimeString();
            $isNet = $this->isTransientNetworkError($e);
            $isBilling = $this->isImageBillingLimitError($e);

            if ($isNet || $isBilling) {
                $placeholderPath = $this->createLocalImagePlaceholder($item, $tenantProfile);
                if ($placeholderPath) {
                    $item->ai_image_path = $placeholderPath;
                    $meta['image_fallback'] = 'local_placeholder';
                    $meta['image_fallback_reason'] = $isNet ? 'network_dns_timeout' : 'billing_limit';
                    $meta['image_fallback_at'] = now()->toDateTimeString();
                } else {
                    $item->ai_image_path = null;
                    $meta['image_fallback'] = null;
                    $meta['image_fallback_reason'] = null;
                    $meta['image_fallback_at'] = null;
                }
            } else {
                $item->ai_image_path = null;
                $meta['image_fallback'] = null;
                $meta['image_fallback_reason'] = null;
                $meta['image_fallback_at'] = null;
            }

            $item->ai_meta = $meta;
            $item->save();

            Log::warning('GenerateAiForContentItem image failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Overlay usato come garanzia quando il brief chiede il logo e il modello non ha potuto editarlo correttamente.

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }

    private function isDemoMode(): bool
    {
        return (bool) config('app.demo_mode', false);
    }

    private function applyDemoPreset(ContentItem $item, array $tenantProfile, array $itemBrain, array $meta): void
    {
        $preset = $this->buildDemoPreset($item, $tenantProfile, $itemBrain);

        $item->title = $item->title ?: ($preset['title'] ?? 'Contenuto demo');
        $item->ai_caption = $preset['caption'];
        $item->ai_hashtags = $preset['hashtags'];
        $item->ai_cta = $preset['cta'];
        $item->ai_image_prompt = $preset['image_prompt'];
        $item->ai_error = null;

        $demoImagePath = $this->chooseDemoImagePath($item);
        if (!$demoImagePath) {
            $demoImagePath = $this->createLocalImagePlaceholder($item, $tenantProfile);
        }
        if ($demoImagePath) {
            $item->ai_image_path = $demoImagePath;
        }

        $demoMeta = [
            'demo_mode' => true,
            'demo_source' => 'hostup_preset_v1',
            'generated_at' => now()->toDateTimeString(),
            'image_source' => $demoImagePath ? 'brand_or_placeholder' : 'none',
        ];

        $item->ai_meta = array_merge($meta, ['demo' => $demoMeta]);

        $assets = is_array($item->assets) ? $item->assets : [];
        if ($demoImagePath) {
            $assets[] = ['type' => 'demo_image', 'path' => $demoImagePath];
            $item->assets = $this->uniqueAssets($assets);
        }

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }

    private function buildDemoPreset(ContentItem $item, array $tenantProfile, array $itemBrain): array
    {
        $brand = trim((string) data_get($tenantProfile, 'business_name', 'Hostup'));
        $angle = trim((string) data_get($itemBrain, 'angle', $item->content_angle ?: 'Automazione affitti brevi'));
        $ctaDefault = trim((string) data_get($itemBrain, 'cta', 'Scrivici per una demo gratuita.'));
        $position = $this->positionInPlan($item);

        $presets = [
            [
                'title' => 'Prezzi dinamici senza stress',
                'caption' => "Uno degli errori più comuni negli affitti brevi è aggiornare i prezzi solo a mano. {$brand} automatizza tariffe e disponibilità in base alla domanda reale, eventi locali e storico prenotazioni. Risultato: più margine e meno camere ferme.",
                'hashtags' => ['#Hostup', '#AffittiBrevi', '#RevenueManagement', '#PropertyManagement', '#Automazione'],
                'cta' => "Vuoi vedere il flusso completo in azione? {$ctaDefault}",
                'image_prompt' => "Dashboard moderna di revenue management per affitti brevi, stile pulito tech, palette brand, scena realistica senza testo.",
            ],
            [
                'title' => 'Canali OTA allineati in tempo reale',
                'caption' => "Sincronizzare manualmente Booking, Airbnb e sito diretto crea overbooking e perdita di tempo. Con {$brand} il calendario resta coerente su tutti i canali: disponibilità, restrizioni e regole vengono aggiornate automaticamente.",
                'hashtags' => ['#Hostup', '#ChannelManager', '#AirbnbHost', '#BookingCom', '#ShortTermRental'],
                'cta' => "Se vuoi, ti mostriamo in 10 minuti come configurarlo sul tuo portfolio.",
                'image_prompt' => "Interfaccia channel manager multi-canale con card OTA, look future-tech, senza watermark e senza testo.",
            ],
            [
                'title' => 'Meno operatività, più controllo',
                'caption' => "La gestione efficace non è fare tutto a mano, ma avere regole chiare e automazioni affidabili. {$brand} riduce attività ripetitive e ti lascia tempo per decisioni strategiche: occupazione, pricing e qualità del servizio.",
                'hashtags' => ['#Hostup', '#HospitalityTech', '#AffittiBreviItalia', '#Automation', '#SmartOperations'],
                'cta' => "Scrivici e prepariamo un setup pilota sui tuoi annunci.",
                'image_prompt' => "Team operativo hospitality che monitora KPI su schermo, stile professionale, luci soft, no testo sovraimpresso.",
            ],
            [
                'title' => 'Dati utili, non solo numeri',
                'caption' => "Guardare solo il tasso di occupazione non basta. Con {$brand} hai indicatori chiave leggibili subito: ADR, RevPAR, conversione e trend per canale. In questo modo ottimizzi ogni settimana con decisioni basate sui dati.",
                'hashtags' => ['#Hostup', '#DataDriven', '#ADR', '#RevPAR', '#RentalBusiness'],
                'cta' => "Ti facciamo vedere quali KPI monitorare da subito nel tuo caso.",
                'image_prompt' => "Cruscotto analytics hospitality con grafici moderni, visuale nitida, stile premium tech, senza testo.",
            ],
            [
                'title' => 'Template operativi pronti',
                'caption' => "Standardizzare i processi fa la differenza quando il numero di annunci cresce. {$brand} applica template e regole ripetibili per velocizzare operazioni quotidiane e mantenere qualità costante.",
                'hashtags' => ['#Hostup', '#Processi', '#PropertyOps', '#Scalabilità', '#DigitalHospitality'],
                'cta' => "Vuoi una checklist pronta per partire? Te la condividiamo.",
                'image_prompt' => "Vista workflow operativo per property management, cards ordinate e look minimal futuristico.",
            ],
            [
                'title' => 'Setup rapido per team piccoli',
                'caption' => "Anche con un team ridotto puoi gestire in modo professionale: meno tool scollegati, più controllo centralizzato. {$brand} organizza attività, priorità e pubblicazione contenuti in un unico flusso chiaro.",
                'hashtags' => ['#Hostup', '#TeamProduttivo', '#Workflow', '#SmartTools', '#BusinessGrowth'],
                'cta' => "Prenota una prova: impostiamo insieme il primo piano operativo.",
                'image_prompt' => "Scrivania moderna con laptop e pannello operativo, mood tech pulito, nessun testo visibile.",
            ],
        ];

        $preset = $presets[$position % count($presets)];
        $preset['caption'] = trim($preset['caption'] . ' ' . ($angle !== '' ? "Focus del post: {$angle}." : ''));
        $preset['cta'] = trim($preset['cta']);

        return $preset;
    }

    private function chooseDemoImagePath(ContentItem $item): ?string
    {
        $paths = BrandAsset::query()
            ->where('tenant_id', $item->tenant_id)
            ->whereNull('content_plan_id')
            ->where('kind', 'image')
            ->orderBy('id')
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();

        if (empty($paths)) {
            return null;
        }

        $pos = $this->positionInPlan($item);
        return $paths[$pos % count($paths)] ?? null;
    }

    private function isQuotaOrRateLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'status code 429')
            || str_contains($message, 'exceeded your current quota')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'insufficient_quota');
    }

    private function isTransientNetworkError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 6')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'curl error 7')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'temporary failure in name resolution');
    }

    private function fallbackText(ContentItem $item, array $tenantProfile, array $itemBrain): array
    {
        $business = trim((string) data_get($tenantProfile, 'business_name', 'Brand'));
        $angle = trim((string) data_get($itemBrain, 'angle', $item->title ?: 'Contenuto'));
        $objective = trim((string) data_get($itemBrain, 'objective', 'Awareness'));
        $cta = trim((string) data_get($itemBrain, 'cta', 'Scrivici per maggiori dettagli.'));
        $industry = trim((string) data_get($tenantProfile, 'industry', 'business'));

        $caption = "{$business}: {$angle}. "
            . "Obiettivo: {$objective}. "
            . "Contenuto generato in fallback temporaneo per limite quota AI.";

        $hashtags = [
            '#marketingdigitale',
            '#contenuti',
            '#' . Str::slug($industry),
            '#strategiabrand',
        ];

        $imagePrompt = "Visual social quadrato per {$business}. "
            . "Tema: {$angle}. Stile pulito e professionale, senza testo sovraimpresso. "
            . "Evita loghi finti, watermark e testo brand inventato. Tutto in italiano.";

        return [
            'caption' => $caption,
            'hashtags' => $hashtags,
            'cta' => $cta,
            'image_prompt' => $imagePrompt,
        ];
    }

    private function isImageBillingLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'billing hard limit')
            || str_contains($message, 'billing_limit_user_error')
            || str_contains($message, 'insufficient_quota');
    }

    private function createLocalImagePlaceholder(ContentItem $item, array $tenantProfile): ?string
    {
        try {
            $brand = trim((string) data_get($tenantProfile, 'business_name', 'Brand'));
            $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $brand), 0, 2) ?: 'BR');
            $palette = (string) data_get($tenantProfile, 'brand_palette', '#0f172a,#2563eb');
            $parts = array_values(array_filter(array_map('trim', explode(',', $palette))));
            $c1 = $parts[0] ?? '#0f172a';
            $c2 = $parts[1] ?? '#2563eb';

            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$c1}"/>
      <stop offset="100%" stop-color="{$c2}"/>
    </linearGradient>
  </defs>
  <rect width="1024" height="1024" fill="url(#g)"/>
  <circle cx="512" cy="512" r="260" fill="rgba(255,255,255,0.16)"/>
  <text x="50%" y="53%" dominant-baseline="middle" text-anchor="middle"
        font-family="Arial, Helvetica, sans-serif" font-size="220" font-weight="700" fill="#ffffff">{$initials}</text>
</svg>
SVG;

            $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.svg';
            Storage::disk('public')->put($filename, $svg);

            return $filename;
        } catch (Throwable) {
            return null;
        }
    }

    private function isVideoFormat(ContentItem $item): bool
    {
        $format = strtolower(trim((string) ($item->format ?? '')));
        return in_array($format, ['reel', 'story', 'video'], true);
    }

    private function shouldUseImageReferenceForVideo(string $briefNormalized): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        $explicit = [
            'parti dalla foto',
            'parti dall immagine',
            'usa questa foto',
            'usa questa immagine',
            'usa esattamente',
            'stessa foto',
            'stessa immagine',
            'come base la foto',
            'come base l immagine',
            'from this photo',
            'from this image',
            'use this image as base',
            'same image',
        ];

        foreach ($explicit as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $logoRuntime
     * @param  array<string, mixed>  $brandDecision
     * @return array<string, mixed>
     */
    private function generateVideoAsset(
        OpenAiService $openAi,
        ContentItem $item,
        string $prompt,
        ?string $selectedBrandImageAbs,
        array $selectedBrandImageAbsList,
        ?string $selectedBrandImagePath,
        array $selectedBrandImagePaths,
        array $logoRuntime,
        array $brandDecision
    ): array {
        $logoRequested = (bool) ($logoRuntime['requested'] ?? false);
        $logoMode = (string) ($logoRuntime['mode'] ?? 'scene');
        $logoAbs = isset($logoRuntime['abs']) && is_string($logoRuntime['abs']) ? $logoRuntime['abs'] : null;
        $logoPath = isset($logoRuntime['path']) && is_string($logoRuntime['path']) ? $logoRuntime['path'] : null;

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $briefRaw = trim((string) data_get($meta, 'manual_brief', ''));
        $briefNorm = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        $explicitReferencePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'image_references.selected_paths', [])
        )));
        $hasExplicitReferences = !empty($explicitReferencePaths);
        $useImageReference = $hasExplicitReferences || $this->shouldUseImageReferenceForVideo($briefNorm);

        $imageReferenceAbs = $useImageReference ? $selectedBrandImageAbs : null;
        $imageReferencePath = $useImageReference ? $selectedBrandImagePath : null;

        $imageReferenceAbsPool = $useImageReference ? array_values(array_filter($selectedBrandImageAbsList, fn ($v) => is_string($v) && $v !== '')) : [];
        $imageReferencePathPool = $useImageReference ? array_values(array_filter($selectedBrandImagePaths, fn ($v) => is_string($v) && $v !== '')) : [];
        if (empty($imageReferenceAbsPool) && is_string($imageReferenceAbs) && $imageReferenceAbs !== '') {
            $imageReferenceAbsPool = [$imageReferenceAbs];
        }
        if (empty($imageReferencePathPool) && is_string($imageReferencePath) && $imageReferencePath !== '') {
            $imageReferencePathPool = [$imageReferencePath];
        }
        $mustEnforceExplicitReferences = $hasExplicitReferences && !empty($imageReferenceAbsPool);
        $validationReferenceAbsPool = array_values(array_slice($imageReferenceAbsPool, 0, 4));
        $generationReferenceAbsPool = $imageReferenceAbsPool;
        $compositionReference = null;
        $compositionMeta = null;

        if ($mustEnforceExplicitReferences && count($imageReferenceAbsPool) >= 2) {
            $compositionReference = $this->buildLockedVideoSceneReference(
                openAi: $openAi,
                brief: $briefRaw !== '' ? $briefRaw : $prompt,
                prompt: $prompt,
                referenceAbsPaths: $imageReferenceAbsPool
            );

            if (is_array($compositionReference) && !empty($compositionReference['abs'])) {
                $generationReferenceAbsPool = [(string) $compositionReference['abs']];
                $compositionMeta = [
                    'used' => true,
                    'attempts' => (int) ($compositionReference['attempts'] ?? 1),
                    'all_present' => (bool) ($compositionReference['all_present'] ?? false),
                    'validation' => $compositionReference['validation'] ?? null,
                ];
            }
        }

        $referenceAbs = null;
        $referencePath = null;
        $referencePaths = [];
        $referenceReason = 'no_reference';
        $tempRefPaths = [];
        $preparedRefPath = null;
        if (is_array($compositionReference) && !empty($compositionReference['abs'])) {
            $tempRefPaths[] = (string) $compositionReference['abs'];
        }

        if (!empty($generationReferenceAbsPool)) {
            if (count($generationReferenceAbsPool) > 1) {
                $collage = $this->buildVideoReferenceCollage(array_slice($generationReferenceAbsPool, 0, 4));
                if (is_string($collage) && $collage !== '') {
                    $referenceAbs = $collage;
                    $referencePath = $imageReferencePathPool[0] ?? null;
                    $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                    $referenceReason = 'brand_multi_image_collage_reference';
                    $tempRefPaths[] = $collage;
                } else {
                    $referenceAbs = $generationReferenceAbsPool[0];
                    $referencePath = $imageReferencePathPool[0] ?? null;
                    $referencePaths = [$referencePath];
                    $referenceReason = 'brand_image_reference_collage_fallback_first';
                }
            } else {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = (is_array($compositionReference) && !empty($compositionReference['abs']))
                    ? 'brand_locked_scene_reference'
                    : 'brand_image_reference';
            }
        }

        if ($logoRequested && $logoAbs) {
            if ($referenceAbs) {
                $composed = $this->buildVideoReferenceImage($referenceAbs, $logoAbs, $logoMode);
                if ($composed) {
                    $referenceAbs = $composed;
                    $referenceReason = count(array_filter($referencePaths, fn ($v) => is_string($v) && $v !== '')) > 1
                        ? 'brand_multi_plus_logo_composed_reference'
                        : 'brand_plus_logo_composed_reference';
                    $tempRefPaths[] = $composed;
                } else {
                    $referenceAbs = $logoAbs;
                    $referencePath = $logoPath;
                    $referencePaths = $logoPath ? [$logoPath] : [];
                    $referenceReason = 'logo_only_reference_fallback';
                }
            } else {
                $referenceAbs = $logoAbs;
                $referencePath = $logoPath;
                $referencePaths = $logoPath ? [$logoPath] : [];
                $referenceReason = 'logo_only_reference';
            }
        }

        $videoPrompt = $prompt;
        $videoPrompt .= ' Genera un video social fluido, cinematografico e realistico.';
        $videoPrompt .= ' Evita testo sovraimpresso, watermark, marchi inventati o nome azienda scritto.';
        $videoPrompt .= ' Se compare testo visibile, deve essere italiano corretto.';
        $videoPrompt .= ' Non iniziare con una foto statica o freeze frame: apri con una scena dinamica in movimento.';
        $videoPrompt .= ' Mantieni coerenza con il brief e con i riferimenti utente senza inventare soggetti casuali.';

        if ($logoRequested && $logoAbs) {
            if ($logoMode === 'background') {
                $videoPrompt .= ' Mantieni il logo reale nello sfondo come presenza grafica discreta e coerente.';
            } else {
                $videoPrompt .= ' Mantieni il logo reale integrato nella scena in modo naturale.';
            }
        } else {
            $videoPrompt .= ' Non aggiungere loghi brand inventati.';
        }

        if (!empty($generationReferenceAbsPool)) {
            if (count($generationReferenceAbsPool) > 1) {
                $videoPrompt .= ' Usa come base combinata i riferimenti visual multipli forniti, mantenendo soggetti e contesto coerenti.';
            } else {
                $videoPrompt .= ' Mantieni il DNA visivo dell immagine brand di riferimento (scena, palette, soggetti).';
            }
        } elseif ($selectedBrandImageAbs) {
            $videoPrompt .= ' Riprendi in modo creativo il tema del brief senza copiare un frame fotografico statico.';
        }
        if ($mustEnforceExplicitReferences) {
            $videoPrompt .= ' VINCOLO OBBLIGATORIO: usa TUTTI i soggetti principali delle immagini di riferimento selezionate dall utente.';
            $videoPrompt .= ' NON sostituire persone, volti, oggetti o veicoli con alternative casuali.';
            $videoPrompt .= ' Se i riferimenti includono persona e auto, nel video devono comparire entrambe in modo riconoscibile.';
        }

        $videoOptions = [
            'model' => (string) (config('openai.video_model') ?: 'sora-2'),
            'seconds' => $this->targetVideoSecondsForFormat($item),
            'size' => $this->targetVideoSizeForFormat($item),
        ];

        if (is_string($referenceAbs) && $referenceAbs !== '') {
            $prepared = $this->prepareVideoReferenceForSize($referenceAbs, (string) $videoOptions['size']);
            if ($prepared) {
                $preparedRefPath = $prepared;
                $referenceAbs = $preparedRefPath;
                $referenceReason .= '_normalized_to_size';
            }
        }

        try {
            $maxAttempts = $mustEnforceExplicitReferences ? 2 : 1;
            $lastValidation = null;
            $lastError = null;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $attemptPrompt = $videoPrompt;
                if ($attempt > 0) {
                    $attemptPrompt .= ' RIGENERAZIONE OBBLIGATORIA: il risultato precedente non rispettava tutti i riferimenti.';
                    $attemptPrompt .= ' Includi chiaramente ogni soggetto richiesto nelle immagini di input.';
                }

                $attemptReferenceAbs = $referenceAbs;
                $attemptReferencePath = $referencePath;
                $attemptReferencePaths = $referencePaths;
                $attemptReferenceReason = $referenceReason;
                $attemptTempPreparedPath = null;

                try {
                    $job = null;
                    try {
                        $job = $openAi->createVideoJob($attemptPrompt, $attemptReferenceAbs, $videoOptions);
                    } catch (Throwable $videoCreateError) {
                        $msg = strtolower($videoCreateError->getMessage());
                        $needsNoRefRetry = str_contains($msg, 'inpaint image must match')
                            || str_contains($msg, 'inpaint')
                            || str_contains($msg, 'input_reference');

                        if (!$needsNoRefRetry) {
                            throw $videoCreateError;
                        }

                        if ($mustEnforceExplicitReferences && !empty($generationReferenceAbsPool)) {
                            $fallbackAbs = $generationReferenceAbsPool[0];
                            $fallbackPrepared = $this->prepareVideoReferenceForSize($fallbackAbs, (string) $videoOptions['size']);
                            if ($fallbackPrepared) {
                                $attemptTempPreparedPath = $fallbackPrepared;
                                $attemptReferenceAbs = $fallbackPrepared;
                                $attemptReferenceReason = 'retry_with_primary_reference_after_inpaint_error_normalized';
                            } else {
                                $attemptReferenceAbs = $fallbackAbs;
                                $attemptReferenceReason = 'retry_with_primary_reference_after_inpaint_error';
                            }

                            $attemptReferencePath = $imageReferencePathPool[0] ?? $attemptReferencePath;
                            $attemptReferencePaths = array_values(array_filter(
                                [$attemptReferencePath],
                                fn ($v) => is_string($v) && $v !== ''
                            ));
                            $job = $openAi->createVideoJob($attemptPrompt, $attemptReferenceAbs, $videoOptions);
                        } else {
                            $attemptReferenceAbs = null;
                            $attemptReferencePath = null;
                            $attemptReferencePaths = [];
                            $attemptReferenceReason = 'retry_without_reference_after_inpaint_error';
                            $job = $openAi->createVideoJob($attemptPrompt, null, $videoOptions);
                        }
                    }

                    $videoId = (string) ($job['id'] ?? '');
                    $jobFinal = $openAi->waitForVideoCompletion($videoId);
                    $videoBytes = $openAi->downloadVideoContent($videoId);
                    $thumbBytes = null;
                    try {
                        $thumbBytes = $openAi->downloadVideoThumbnail($videoId);
                    } catch (Throwable) {
                        $thumbBytes = null;
                    }

                    $validation = null;
                    if ($mustEnforceExplicitReferences && is_string($thumbBytes) && $thumbBytes !== '') {
                        $tmpDir = storage_path('app/tmp');
                        if (!is_dir($tmpDir)) {
                            @mkdir($tmpDir, 0775, true);
                        }
                        $tmpThumbPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-validate-' . Str::uuid()->toString() . '.' . $this->detectImageExtensionFromBytes($thumbBytes);
                        @file_put_contents($tmpThumbPath, $thumbBytes);
                        if (is_file($tmpThumbPath)) {
                            $validation = $openAi->validateVideoFrameWithReferences(
                                brief: $briefRaw !== '' ? $briefRaw : $prompt,
                                frameAbsolutePath: $tmpThumbPath,
                                referenceAbsolutePaths: !empty($validationReferenceAbsPool)
                                    ? $validationReferenceAbsPool
                                    : array_slice($generationReferenceAbsPool, 0, 4)
                            );
                            @unlink($tmpThumbPath);
                        }

                        if (is_array($validation)) {
                            $lastValidation = $validation;
                            $allPresent = (bool) ($validation['all_present'] ?? false);
                            if (!$allPresent && ($attempt + 1) < $maxAttempts) {
                                continue;
                            }
                            if (!$allPresent) {
                                $attemptReferenceReason .= '_validation_failed_accept_last_attempt';
                            }
                        }
                    }

                    $videoExt = $this->detectVideoExtensionFromBytes($videoBytes);
                    $videoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $videoExt;
                    Storage::disk('public')->put($videoPath, $videoBytes);

                    $thumbPath = null;
                    if (is_string($thumbBytes) && $thumbBytes !== '') {
                        $thumbExt = $this->detectImageExtensionFromBytes($thumbBytes);
                        $thumbPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $thumbExt;
                        Storage::disk('public')->put($thumbPath, $thumbBytes);
                    }

                    return [
                        'source' => 'sora_video_generation',
                        'video_id' => $videoId,
                        'video_path' => $videoPath,
                        'thumbnail_path' => $thumbPath,
                        'reference_path' => $attemptReferencePath,
                        'reference_paths' => array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== '')),
                        'reference_reason' => $attemptReferenceReason,
                        'reference_validation' => $validation ?? $lastValidation,
                        'composition_reference' => $compositionMeta,
                        'generation_attempts' => $attempt + 1,
                        'job_status' => (string) ($jobFinal['status'] ?? 'completed'),
                        'brand_selection' => $brandDecision,
                    ];
                } catch (Throwable $attemptError) {
                    $lastError = $attemptError;
                    if (($attempt + 1) >= $maxAttempts) {
                        throw $attemptError;
                    }
                } finally {
                    if (is_string($attemptTempPreparedPath) && $attemptTempPreparedPath !== '' && is_file($attemptTempPreparedPath)) {
                        @unlink($attemptTempPreparedPath);
                    }
                }
            }

            if ($lastError instanceof Throwable) {
                throw $lastError;
            }

            throw new \RuntimeException('Video generation failed without explicit error.');
        } finally {
            if (is_string($preparedRefPath) && $preparedRefPath !== '' && is_file($preparedRefPath)) {
                @unlink($preparedRefPath);
            }
            foreach ($tempRefPaths as $tmpPath) {
                if (is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath)) {
                    @unlink($tmpPath);
                }
            }
        }
    }

    private function targetVideoSecondsForFormat(ContentItem $item): string
    {
        $seconds = trim((string) (config('openai.video_seconds') ?: '8'));
        if (!in_array($seconds, ['4', '8', '12'], true)) {
            $seconds = '8';
        }

        $format = strtolower(trim((string) ($item->format ?? '')));
        if ($format === 'story' && $seconds === '12') {
            return '8';
        }

        return $seconds;
    }

    private function targetVideoSizeForFormat(ContentItem $item): string
    {
        $configured = trim((string) (config('openai.video_size') ?: ''));
        if (in_array($configured, ['720x1280', '1280x720', '1080x1920'], true)) {
            return $configured;
        }

        $format = strtolower(trim((string) ($item->format ?? '')));
        if (in_array($format, ['reel', 'story'], true)) {
            return '720x1280';
        }

        return '1280x720';
    }

    private function buildVideoReferenceImage(string $baseImageAbs, string $logoAbs, string $logoMode = 'background'): ?string
    {
        $baseInfo = @getimagesize($baseImageAbs);
        $logoInfo = @getimagesize($logoAbs);
        if (!is_array($baseInfo) || !isset($baseInfo['mime']) || !is_array($logoInfo) || !isset($logoInfo['mime'])) {
            return null;
        }

        $target = $this->loadRasterImage($baseImageAbs, (string) $baseInfo['mime']);
        $logo = $this->loadRasterImage($logoAbs, (string) $logoInfo['mime']);
        if (!$target || !$logo) {
            if (is_resource($target) || $target instanceof \GdImage) {
                imagedestroy($target);
            }
            if (is_resource($logo) || $logo instanceof \GdImage) {
                imagedestroy($logo);
            }
            return null;
        }

        $tw = imagesx($target);
        $th = imagesy($target);
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        if ($tw < 10 || $th < 10 || $lw < 2 || $lh < 2) {
            imagedestroy($target);
            imagedestroy($logo);
            return null;
        }

        if ($logoMode === 'background') {
            $maxLogoW = max(200, (int) round($tw * 0.62));
            $maxLogoH = max(200, (int) round($th * 0.62));
            $opacity = 0.18;
            $x = (int) round(($tw - min($maxLogoW, $lw)) / 2);
            $y = (int) round(($th - min($maxLogoH, $lh)) / 2);
        } else {
            $maxLogoW = max(90, (int) round($tw * 0.22));
            $maxLogoH = max(90, (int) round($th * 0.22));
            $opacity = 0.92;
            $margin = max(12, (int) round(min($tw, $th) * 0.03));
            $x = $tw - min($maxLogoW, $lw) - $margin;
            $y = $th - min($maxLogoH, $lh) - $margin;
        }

        $scale = min($maxLogoW / $lw, $maxLogoH / $lh, 1.0);
        $newW = max(1, (int) round($lw * $scale));
        $newH = max(1, (int) round($lh * $scale));

        $logoResized = imagecreatetruecolor($newW, $newH);
        imagealphablending($logoResized, false);
        imagesavealpha($logoResized, true);
        $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
        imagefilledrectangle($logoResized, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $newW, $newH, $lw, $lh);
        $this->applyOpacity($logoResized, $opacity);

        imagealphablending($target, true);
        imagesavealpha($target, true);
        $x = (int) max(0, min($tw - $newW, $x));
        $y = (int) max(0, min($th - $newH, $y));
        imagecopy($target, $logoResized, $x, $y, 0, 0, $newW, $newH);

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-ref-' . Str::uuid()->toString() . '.png';
        $saved = @imagepng($target, $tmpPath, 8);

        imagedestroy($logoResized);
        imagedestroy($logo);
        imagedestroy($target);

        if (!$saved) {
            return null;
        }

        return $tmpPath;
    }

    private function buildVideoReferenceCollage(array $imageAbsPaths): ?string
    {
        $paths = array_values(array_filter($imageAbsPaths, fn ($p) => is_string($p) && $p !== '' && is_file($p)));
        if (empty($paths)) {
            return null;
        }

        $paths = array_slice($paths, 0, 4);
        $count = count($paths);
        $cols = $count === 1 ? 1 : 2;
        $rows = $count <= 2 ? 1 : 2;

        $cellSize = 640;
        $canvasW = $cols * $cellSize;
        $canvasH = $rows * $cellSize;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        $bg = imagecolorallocate($canvas, 10, 14, 22);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $bg);

        foreach ($paths as $idx => $abs) {
            $info = @getimagesize($abs);
            if (!is_array($info) || !isset($info['mime'])) {
                continue;
            }

            $img = $this->loadRasterImage($abs, (string) $info['mime']);
            if (!$img) {
                continue;
            }

            $srcW = imagesx($img);
            $srcH = imagesy($img);
            if ($srcW < 1 || $srcH < 1) {
                imagedestroy($img);
                continue;
            }

            $col = $idx % $cols;
            $row = (int) floor($idx / $cols);
            $cellX = $col * $cellSize;
            $cellY = $row * $cellSize;

            $scale = max($cellSize / $srcW, $cellSize / $srcH);
            $dstW = (int) round($srcW * $scale);
            $dstH = (int) round($srcH * $scale);
            $dstX = $cellX + (int) round(($cellSize - $dstW) / 2);
            $dstY = $cellY + (int) round(($cellSize - $dstH) / 2);

            imagecopyresampled($canvas, $img, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($img);
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-ref-collage-' . Str::uuid()->toString() . '.png';
        $saved = @imagepng($canvas, $tmpPath, 8);
        imagedestroy($canvas);

        if (!$saved) {
            return null;
        }

        return $tmpPath;
    }

    /**
     * Crea un'immagine "scene-lock" che integra tutti i riferimenti espliciti
     * prima della generazione video, con validazione visuale.
     *
     * @param  array<int, string>  $referenceAbsPaths
     * @return array<string, mixed>|null
     */
    private function buildLockedVideoSceneReference(
        OpenAiService $openAi,
        string $brief,
        string $prompt,
        array $referenceAbsPaths
    ): ?array {
        $refs = array_values(array_filter(
            array_slice($referenceAbsPaths, 0, 4),
            fn ($v) => is_string($v) && $v !== '' && is_file($v)
        ));

        if (empty($refs)) {
            return null;
        }

        $best = null;
        $bestScore = null;
        $missingHint = [];
        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $composePrompt = 'Crea una singola immagine di scena che integri TUTTI i soggetti principali presenti nelle immagini di riferimento.';
            $composePrompt .= ' Non omettere nessun soggetto richiesto.';
            $composePrompt .= ' Mantieni volti, persone, oggetti e veicoli riconoscibili e coerenti con i riferimenti.';
            $composePrompt .= ' Niente testo, niente watermark, niente loghi inventati.';
            $composePrompt .= ' Brief: ' . Str::limit(trim($brief !== '' ? $brief : $prompt), 500, '');
            $composePrompt .= ' Direzione creativa: ' . Str::limit(trim($prompt), 380, '');

            if (!empty($missingHint)) {
                $composePrompt .= ' Nel tentativo precedente mancavano alcuni soggetti: riferimenti #' . implode(', #', $missingHint) . '.';
                $composePrompt .= ' In questo tentativo devono essere visibili tutti i soggetti mancanti.';
            }

            try {
                $img = $openAi->generateImageEditBase64($composePrompt, $refs);
                $bytes = base64_decode((string) ($img['b64'] ?? ''), true);
                if (!is_string($bytes) || $bytes === '') {
                    continue;
                }

                $tmpDir = storage_path('app/tmp');
                if (!is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0775, true);
                }
                $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-scene-lock-' . Str::uuid()->toString() . '.png';
                @file_put_contents($tmpPath, $bytes);
                if (!is_file($tmpPath)) {
                    continue;
                }

                $validation = $openAi->validateVideoFrameWithReferences(
                    brief: $brief !== '' ? $brief : $prompt,
                    frameAbsolutePath: $tmpPath,
                    referenceAbsolutePaths: $refs
                );

                $allPresent = (bool) data_get($validation, 'all_present', false);
                $confidence = (float) data_get($validation, 'confidence', 0.0);
                $missing = array_values(array_unique(array_filter(array_map(
                    fn ($v) => (int) $v,
                    (array) data_get($validation, 'missing_indexes', [])
                ), fn ($v) => $v > 0)));
                $missingHint = $missing;

                $score = ($allPresent ? 0 : (count($missing) * 100 + 10)) - (int) round($confidence * 10);

                if ($best === null || $bestScore === null || $score < $bestScore) {
                    if (is_array($best) && !empty($best['abs']) && is_string($best['abs']) && is_file($best['abs'])) {
                        @unlink($best['abs']);
                    }
                    $best = [
                        'abs' => $tmpPath,
                        'all_present' => $allPresent,
                        'validation' => $validation,
                        'attempts' => $attempt + 1,
                    ];
                    $bestScore = $score;
                } else {
                    @unlink($tmpPath);
                }

                if ($allPresent) {
                    break;
                }
            } catch (Throwable $e) {
                Log::info('buildLockedVideoSceneReference skipped attempt', [
                    'attempt' => $attempt + 1,
                    'error' => Str::limit($e->getMessage(), 180, ''),
                ]);
            }
        }

        return $best;
    }

    private function detectVideoExtensionFromBytes(string $bytes): string
    {
        if (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }

        return 'mp4';
    }

    private function detectImageExtensionFromBytes(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'png';
        }

        if (str_starts_with($bytes, "RIFF") && str_contains(substr($bytes, 0, 16), "WEBP")) {
            return 'webp';
        }

        return 'jpg';
    }

    private function prepareVideoReferenceForSize(string $sourceAbs, string $size): ?string
    {
        if (!is_file($sourceAbs)) {
            return null;
        }

        [$targetW, $targetH] = $this->parseVideoSize($size);
        if ($targetW < 16 || $targetH < 16) {
            return null;
        }

        $info = @getimagesize($sourceAbs);
        if (!is_array($info) || !isset($info['mime'])) {
            return null;
        }

        $source = $this->loadRasterImage($sourceAbs, (string) $info['mime']);
        if (!$source) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($source);
            return null;
        }

        if ($srcW === $targetW && $srcH === $targetH) {
            imagedestroy($source);
            return null;
        }

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, true);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $black);

        // cover crop to match exact size requested by video API
        $scale = max($targetW / $srcW, $targetH / $srcH);
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);
        $dstX = (int) round(($targetW - $newW) / 2);
        $dstY = (int) round(($targetH - $newH) / 2);
        imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-ref-sized-' . Str::uuid()->toString() . '.png';
        $saved = @imagepng($canvas, $tmpPath, 8);

        imagedestroy($canvas);
        imagedestroy($source);

        if (!$saved) {
            return null;
        }

        return $tmpPath;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseVideoSize(string $size): array
    {
        $size = trim(strtolower($size));
        if (!preg_match('/^(\d{2,5})x(\d{2,5})$/', $size, $m)) {
            return [720, 1280];
        }
        return [(int) $m[1], (int) $m[2]];
    }

    private function applyBrandLogoOverlay(ContentItem $item, array $strategy, array $meta): ?array
    {
        try {
            $imageSource = (string) data_get($meta, 'image_generation.source', '');
            if (!$this->shouldApplyLogoOverlay($item, $imageSource, $meta)) {
                return ['applied' => false, 'reason' => 'overlay_policy_skip'];
            }

            $imagePath = trim((string) $item->ai_image_path);
            if ($imagePath === '') {
                return ['applied' => false, 'reason' => 'missing_image'];
            }

            $logoPath = $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id);
            if ($logoPath === null) {
                return ['applied' => false, 'reason' => 'missing_logo_asset'];
            }

            $disk = Storage::disk('public');
            if (!$disk->exists($imagePath)) {
                return ['applied' => false, 'reason' => 'image_not_found'];
            }
            if (!$disk->exists($logoPath)) {
                return ['applied' => false, 'reason' => 'logo_not_found'];
            }

            $imageAbs = $disk->path($imagePath);
            $logoAbs = $disk->path($logoPath);
            $imgInfo = @getimagesize($imageAbs);
            $logoInfo = @getimagesize($logoAbs);

            if (!is_array($imgInfo) || !isset($imgInfo['mime'])) {
                return ['applied' => false, 'reason' => 'invalid_target_image'];
            }
            if (!is_array($logoInfo) || !isset($logoInfo['mime'])) {
                return ['applied' => false, 'reason' => 'invalid_logo_image_or_svg'];
            }

            $target = $this->loadRasterImage($imageAbs, (string) $imgInfo['mime']);
            $logo = $this->loadRasterImage($logoAbs, (string) $logoInfo['mime']);
            if (!$target || !$logo) {
                if (is_resource($target) || $target instanceof \GdImage) {
                    imagedestroy($target);
                }
                if (is_resource($logo) || $logo instanceof \GdImage) {
                    imagedestroy($logo);
                }
                return ['applied' => false, 'reason' => 'unsupported_image_format'];
            }

            $tw = imagesx($target);
            $th = imagesy($target);
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            if ($tw < 10 || $th < 10 || $lw < 2 || $lh < 2) {
                imagedestroy($target);
                imagedestroy($logo);
                return ['applied' => false, 'reason' => 'invalid_dimensions'];
            }

            $overlayMode = (string) data_get($meta, 'logo_runtime.mode', 'corner');
            if ($overlayMode === 'background') {
                $maxLogoW = max(200, (int) round($tw * 0.62));
                $maxLogoH = max(200, (int) round($th * 0.62));
            } else {
                $maxLogoW = max(90, (int) round($tw * 0.2));
                $maxLogoH = max(90, (int) round($th * 0.2));
            }
            $scale = min($maxLogoW / $lw, $maxLogoH / $lh, 1.0);
            $newW = max(1, (int) round($lw * $scale));
            $newH = max(1, (int) round($lh * $scale));

            $logoResized = imagecreatetruecolor($newW, $newH);
            imagealphablending($logoResized, false);
            imagesavealpha($logoResized, true);
            $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
            imagefilledrectangle($logoResized, 0, 0, $newW, $newH, $transparent);
            imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $newW, $newH, $lw, $lh);

            $style = $this->overlayStyleForItem($item, $tw, $th, $newW, $newH, $overlayMode);
            if (($style['opacity'] ?? 1.0) < 1.0) {
                $this->applyOpacity($logoResized, (float) $style['opacity']);
            }

            $angle = (float) ($style['angle'] ?? 0.0);
            if (abs($angle) > 0.001) {
                $rotated = imagerotate($logoResized, $angle, imagecolorallocatealpha($logoResized, 0, 0, 0, 127));
                if ($rotated !== false) {
                    imagesavealpha($rotated, true);
                    imagedestroy($logoResized);
                    $logoResized = $rotated;
                    $newW = imagesx($logoResized);
                    $newH = imagesy($logoResized);
                }
            }

            imagealphablending($target, true);
            imagesavealpha($target, true);

            $x = (int) max(0, min($tw - $newW, (int) ($style['x'] ?? ($tw - $newW))));
            $y = (int) max(0, min($th - $newH, (int) ($style['y'] ?? ($th - $newH))));
            imagecopy($target, $logoResized, $x, $y, 0, 0, $newW, $newH);

            $saved = $this->saveRasterImage($target, $imageAbs, (string) $imgInfo['mime']);

            imagedestroy($logoResized);
            imagedestroy($target);
            imagedestroy($logo);

            if (!$saved) {
                return ['applied' => false, 'reason' => 'save_failed'];
            }

            return [
                'applied' => true,
                'logo_path' => $logoPath,
                'position' => $style['name'] ?? 'overlay',
                'size_ratio' => round($newW / max(1, $tw), 4),
                'applied_at' => now()->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            return [
                'applied' => false,
                'reason' => 'overlay_exception',
                'error' => Str::limit($e->getMessage(), 160, ''),
            ];
        }
    }

    private function shouldApplyLogoOverlay(ContentItem $item, string $imageSource, array $meta = []): bool
    {
        return (bool) data_get($meta, 'logo_runtime.force', false);
    }

    private function overlayStyleForItem(ContentItem $item, int $tw, int $th, int $w, int $h, string $mode = 'corner'): array
    {
        if ($mode === 'background') {
            return [
                'name' => 'center-background',
                'x' => (int) round(($tw - $w) / 2),
                'y' => (int) round(($th - $h) / 2),
                'angle' => 0,
                'opacity' => 0.18,
            ];
        }

        $m = max(12, (int) round(min($tw, $th) * 0.03));
        $seed = ($item->id + $this->positionInPlan($item)) % 4;

        return match ($seed) {
            0 => [
                'name' => 'corner-bottom-right',
                'x' => $tw - $w - $m,
                'y' => $th - $h - $m,
                'angle' => 0,
                'opacity' => 0.95,
            ],
            1 => [
                'name' => 'corner-top-left',
                'x' => $m,
                'y' => $m,
                'angle' => 0,
                'opacity' => 0.9,
            ],
            2 => [
                'name' => 'corner-top-right',
                'x' => $tw - $w - $m,
                'y' => $m,
                'angle' => 0,
                'opacity' => 0.9,
            ],
            default => [
                'name' => 'corner-bottom-left',
                'x' => $m,
                'y' => $th - $h - $m,
                'angle' => 0,
                'opacity' => 0.92,
            ],
        };
    }

    private function applyOpacity($img, float $opacity): void
    {
        $opacity = max(0.15, min(1.0, $opacity));
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $alphaFloat = 1.0 - ($a / 127.0);
                $alphaFloat *= $opacity;
                $newA = 127 - (int) round(127 * $alphaFloat);
                $newA = max(0, min(127, $newA));

                $color = imagecolorallocatealpha($img, $r, $g, $b, $newA);
                imagesetpixel($img, $x, $y, $color);
            }
        }
    }

    private function resolveLogoRuntime(ContentItem $item, array $strategy, array $meta, ?string $selectedBrandImageAbs): array
    {
        $brief = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        $requested = $this->briefRequestsLogoAsset($brief);
        $mode = $this->briefWantsBackgroundLogo($brief) ? 'background' : 'scene';
        $variant = $this->briefRequestedLogoVariant($brief);

        $candidates = $this->loadRasterLogoCandidates($meta, (int) $item->tenant_id);
        $selected = null;
        $reason = $requested ? 'logo_requested_but_not_found' : 'default_logo_not_found';
        $targetBrightness = $this->estimateImageBrightness($selectedBrandImageAbs);

        if (!$requested) {
            $defaultPath = trim((string) $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id));
            if ($defaultPath !== '') {
                foreach ($candidates as $candidate) {
                    if (($candidate['path'] ?? null) === $defaultPath) {
                        $selected = $candidate;
                        $reason = 'default_logo_path';
                        break;
                    }
                }
            }
            if ($selected === null && !empty($candidates)) {
                $selected = $candidates[0];
                $reason = 'default_latest_logo';
            }
        } elseif (!empty($candidates)) {
            $desired = $variant;
            if ($desired === 'auto') {
                if (is_float($targetBrightness)) {
                    $desired = $targetBrightness >= 0.56 ? 'dark' : 'light';
                    $reason = 'auto_logo_contrast_' . $desired;
                } else {
                    $desired = 'auto';
                    $reason = 'auto_logo_default_latest';
                }
            } else {
                $reason = 'brief_logo_variant_' . $desired;
            }

            if (in_array($desired, ['light', 'dark'], true)) {
                foreach ($candidates as $candidate) {
                    if (($candidate['tone'] ?? null) === $desired) {
                        $selected = $candidate;
                        $reason .= '_name_match';
                        break;
                    }
                }

                if ($selected === null) {
                    $ranked = $candidates;
                    usort($ranked, function (array $a, array $b) use ($desired) {
                        $ba = $a['brightness'] ?? null;
                        $bb = $b['brightness'] ?? null;
                        if (!is_float($ba) && !is_float($bb)) {
                            return 0;
                        }
                        if (!is_float($ba)) {
                            return 1;
                        }
                        if (!is_float($bb)) {
                            return -1;
                        }
                        if ($ba === $bb) {
                            return 0;
                        }
                        if ($desired === 'light') {
                            return $ba < $bb ? 1 : -1;
                        }
                        return $ba < $bb ? -1 : 1;
                    });
                    $selected = $ranked[0] ?? null;
                    if ($selected !== null) {
                        $reason .= '_brightness_match';
                    }
                }
            }

            if ($selected === null) {
                $selected = $candidates[0];
                $reason = 'logo_requested_default_latest';
            }
        }

        if ($selected === null) {
            $fallbackPath = trim((string) $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id));
            if ($fallbackPath !== '') {
                $disk = Storage::disk('public');
                if ($disk->exists($fallbackPath)) {
                    $fallbackAbs = $disk->path($fallbackPath);
                    $mime = strtolower((string) (mime_content_type($fallbackAbs) ?: ''));
                    if (str_starts_with($mime, 'image/') && !str_contains($mime, 'svg')) {
                        $selected = [
                            'path' => $fallbackPath,
                            'abs' => $fallbackAbs,
                            'tone' => null,
                            'brightness' => null,
                        ];
                        $reason = 'logo_fallback_from_strategy';
                    }
                }
            }
        }

        return [
            'requested' => $requested,
            'mode' => $mode,
            'variant' => $variant,
            'path' => is_array($selected) ? (string) ($selected['path'] ?? '') : null,
            'abs' => is_array($selected) ? (string) ($selected['abs'] ?? '') : null,
            'reason' => $reason,
        ];
    }

    private function loadRasterLogoCandidates(array $meta, int $tenantId): array
    {
        $rows = [];

        foreach ((array) data_get($meta, 'brand_assets', []) as $asset) {
            if (!is_array($asset) || (($asset['kind'] ?? null) !== 'logo')) {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'original_name' => trim((string) ($asset['original_name'] ?? '')),
            ];
        }

        $db = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'logo')
            ->orderByDesc('id')
            ->limit(24)
            ->get(['path', 'original_name']);

        foreach ($db as $row) {
            $path = trim((string) ($row->path ?? ''));
            if ($path === '') {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'original_name' => trim((string) ($row->original_name ?? '')),
            ];
        }

        $disk = Storage::disk('public');
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === '' || isset($seen[$path]) || !$disk->exists($path)) {
                continue;
            }
            $seen[$path] = true;
            $abs = $disk->path($path);
            $mime = strtolower((string) (mime_content_type($abs) ?: ''));
            if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
                continue;
            }
            $name = trim((string) ($row['original_name'] ?? basename($path)));
            $tone = $this->detectLogoToneHint($name . ' ' . basename($path));
            $brightness = $this->estimateImageBrightness($abs);
            $out[] = [
                'path' => $path,
                'abs' => $abs,
                'tone' => $tone,
                'brightness' => $brightness,
            ];
        }

        return $out;
    }

    private function detectLogoToneHint(string $text): ?string
    {
        $v = $this->normalizeText($text);
        if ($v === '') {
            return null;
        }

        $light = ['chiaro', 'bianco', 'white', 'light', 'clear', 'invertito', 'inverted'];
        $dark = ['scuro', 'nero', 'black', 'dark'];

        foreach ($light as $needle) {
            if (str_contains($v, $needle)) {
                return 'light';
            }
        }
        foreach ($dark as $needle) {
            if (str_contains($v, $needle)) {
                return 'dark';
            }
        }

        return null;
    }

    private function briefRequestsLogoAsset(string $briefNormalized): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        foreach (['logo', 'logotipo', 'marchio', 'brandmark', 'brand mark'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function briefWantsBackgroundLogo(string $briefNormalized): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        foreach (['dietro', 'sfondo', 'background', 'watermark', 'filigrana'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function briefRequestedLogoVariant(string $briefNormalized): string
    {
        if ($briefNormalized === '') {
            return 'auto';
        }

        $hasLight = false;
        foreach (['chiaro', 'bianco', 'white', 'light'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                $hasLight = true;
                break;
            }
        }

        $hasDark = false;
        foreach (['scuro', 'nero', 'black', 'dark'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                $hasDark = true;
                break;
            }
        }

        if ($hasLight && !$hasDark) {
            return 'light';
        }
        if ($hasDark && !$hasLight) {
            return 'dark';
        }

        return 'auto';
    }

    private function estimateImageBrightness(?string $absolutePath): ?float
    {
        if (!is_string($absolutePath) || trim($absolutePath) === '' || !is_file($absolutePath)) {
            return null;
        }

        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }

        $img = $this->loadRasterImage($absolutePath, $mime);
        if (!$img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);
            return null;
        }

        $step = max(1, (int) floor(max($w, $h) / 64));
        $sum = 0.0;
        $count = 0;

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a >= 126) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $alpha = 1.0 - ($a / 127.0);
                $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255.0;
                $sum += ($lum * $alpha);
                $count++;
            }
        }

        imagedestroy($img);

        if ($count < 1) {
            return null;
        }

        return $sum / $count;
    }

    private function resolveLogoPath(array $strategy, array $meta, int $tenantId): ?string
    {
        $forcedLogoPath = trim((string) data_get($meta, 'logo_runtime.path', ''));
        if ($forcedLogoPath !== '') {
            return $forcedLogoPath;
        }

        $logoPath = trim((string) data_get($strategy, 'brand_references.logo_path', ''));
        if ($logoPath !== '') {
            return $logoPath;
        }

        $assets = (array) data_get($meta, 'brand_assets', []);
        foreach ($assets as $asset) {
            if (($asset['kind'] ?? null) === 'logo' && !empty($asset['path'])) {
                return (string) $asset['path'];
            }
        }

        $dbLogo = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'logo')
            ->orderByDesc('id')
            ->first(['path']);

        if ($dbLogo && !empty($dbLogo->path)) {
            return (string) $dbLogo->path;
        }

        return null;
    }

    private function resolveRasterLogoAbsolutePath(array $strategy, array $meta, int $tenantId): ?string
    {
        $logoRel = $this->resolveLogoPath($strategy, $meta, $tenantId);
        if (!$logoRel) {
            return null;
        }
        $disk = Storage::disk('public');
        if (!$disk->exists($logoRel)) {
            return null;
        }
        $abs = $disk->path($logoRel);
        $mime = strtolower((string) (mime_content_type($abs) ?: ''));
        if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }
        return $abs;
    }

    private function shouldEmbedLogoInScene(ContentItem $item, ?string $selectedBrandImageAbs, ?string $logoAbs): bool
    {
        if (!$selectedBrandImageAbs || !$logoAbs) {
            return false;
        }
        // Saltuario e deterministico (~1 ogni 3 post).
        return ($this->positionInPlan($item) % 3) === 0;
    }

    private function loadRasterImage(string $path, string $mime)
    {
        return match (strtolower($mime)) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
    }

    private function saveRasterImage($image, string $path, string $mime): bool
    {
        return match (strtolower($mime)) {
            'image/png' => @imagepng($image, $path, 9),
            'image/jpeg', 'image/jpg' => @imagejpeg($image, $path, 92),
            'image/webp' => function_exists('imagewebp') ? @imagewebp($image, $path, 90) : false,
            default => false,
        };
    }

    private function maxTextSimilarity(string $text, array $candidates): float
    {
        $text = $this->normalizeText($text);
        if ($text === '' || empty($candidates)) {
            return 0.0;
        }

        $max = 0.0;
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeText((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $score = $this->textSimilarityScore($text, $candidate);
            if ($score > $max) {
                $max = $score;
            }
        }
        return $max;
    }

    private function closestText(string $text, array $candidates): ?string
    {
        $base = $this->normalizeText($text);
        $best = null;
        $bestScore = -1.0;
        foreach ($candidates as $candidate) {
            $c = $this->normalizeText((string) $candidate);
            if ($c === '') {
                continue;
            }
            $score = $this->textSimilarityScore($base, $c);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $candidate;
            }
        }
        return $best;
    }

    private function textSimilarityScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $ta = array_values(array_filter(explode(' ', $a)));
        $tb = array_values(array_filter(explode(' ', $b)));
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }

        $ia = array_count_values($ta);
        $ib = array_count_values($tb);
        $shared = 0;
        foreach ($ia as $token => $count) {
            if (isset($ib[$token])) {
                $shared += min($count, $ib[$token]);
            }
        }
        $union = max(1, array_sum($ia) + array_sum($ib) - $shared);
        $jaccard = $shared / $union;

        similar_text($a, $b, $percent);
        $charScore = $percent / 100.0;

        return min(1.0, ($jaccard * 0.65) + ($charScore * 0.35));
    }

    private function normalizeText(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    private function resolveBrandImageSources(array $strategy, array $meta, int $tenantId): array
    {
        $paths = (array) data_get($strategy, 'brand_references.reference_images', []);
        $paths = array_values(array_filter(array_map('strval', $paths)));

        if (empty($paths)) {
            $assets = (array) data_get($meta, 'brand_assets', []);
            foreach ($assets as $asset) {
                if (($asset['kind'] ?? null) === 'image' && !empty($asset['path'])) {
                    $paths[] = (string) $asset['path'];
                }
            }
        }

        if (empty($paths)) {
            $dbAssets = BrandAsset::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('content_plan_id')
                ->where('kind', 'image')
                ->orderByDesc('id')
                ->limit(48)
                ->get(['path']);

            foreach ($dbAssets as $asset) {
                if (!empty($asset->path)) {
                    $paths[] = (string) $asset->path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function decideBrandImageUsage(ContentItem $item, array $paths, ?OpenAiService $openAi = null): array
    {
        $public = Storage::disk('public');
        $valid = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !$public->exists($path)) {
                continue;
            }
            $mime = mime_content_type($public->path($path)) ?: '';
            $mime = strtolower($mime);
            if (str_starts_with($mime, 'image/') && !str_contains($mime, 'svg')) {
                $valid[] = $path;
            }
        }
        $valid = array_values(array_unique($valid));
        if (empty($valid)) {
            return ['use_brand' => false, 'path' => null, 'reason' => 'no_valid_brand_images'];
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $valid = $this->reorderValidPathsByMetaRecency($valid, $meta);
        $explicitPaths = $this->extractExplicitReferencePaths($meta, $valid);
        if (!empty($explicitPaths)) {
            return [
                'use_brand' => true,
                'path' => $explicitPaths[0],
                'paths' => $explicitPaths,
                'reason' => 'explicit_user_image_references',
                'confidence' => 1.0,
                'selected_count' => count($explicitPaths),
            ];
        }

        $preferredPath = trim((string) data_get($meta, 'image_preference.path', ''));
        if ($preferredPath !== '' && in_array($preferredPath, $valid, true)) {
            return [
                'use_brand' => true,
                'path' => $preferredPath,
                'reason' => (string) (data_get($meta, 'image_preference.reason', 'manual_preference')),
                'confidence' => (float) (data_get($meta, 'image_preference.confidence', 1.0)),
            ];
        }

        $briefDriven = $this->selectBrandImageFromBrief($item, $valid);
        if (is_array($briefDriven) && !empty($briefDriven['path'])) {
            return $briefDriven;
        }

        if ($openAi instanceof OpenAiService) {
            $visionDriven = $this->selectBrandImageByVision($item, $valid, $openAi);
            if (is_array($visionDriven) && !empty($visionDriven['path'])) {
                return $visionDriven;
            }
        }

        $position = $this->positionInPlan($item);
        $totalInPlan = $this->totalItemsInPlan($item);
        $idx = $position % count($valid);

        // Usa sempre immagini brand quando presenti, ciclano in base alla posizione del post nel piano.
        return [
            'use_brand' => true,
            'path' => $valid[$idx],
            'reason' => 'always_use_brand_cycle',
            'position' => $position,
            'total_in_plan' => $totalInPlan,
            'valid_pool' => count($valid),
        ];
    }

    private function reorderValidPathsByMetaRecency(array $validPaths, array $meta): array
    {
        if (empty($validPaths)) {
            return [];
        }
        $validLookup = array_fill_keys($validPaths, true);
        $ordered = [];

        foreach ((array) data_get($meta, 'brand_assets', []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['kind'] ?? null) !== 'image') {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '' || !isset($validLookup[$path])) {
                continue;
            }
            $ordered[] = $path;
            unset($validLookup[$path]);
        }

        foreach ($validPaths as $path) {
            if (isset($validLookup[$path])) {
                $ordered[] = $path;
                unset($validLookup[$path]);
            }
        }

        return array_values(array_unique($ordered));
    }

    private function extractExplicitReferencePaths(array $meta, array $validPaths): array
    {
        if (empty($validPaths)) {
            return [];
        }

        $validLookup = array_fill_keys($validPaths, true);
        $paths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'image_references.selected_paths', [])
        )));

        if (empty($paths)) {
            return [];
        }

        $out = [];
        foreach ($paths as $path) {
            if (!isset($validLookup[$path])) {
                continue;
            }
            $out[] = $path;
            if (count($out) >= 4) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    private function selectBrandImageFromBrief(ContentItem $item, array $validPaths): ?array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $brief = trim((string) data_get($meta, 'manual_brief', ''));
        if ($brief === '') {
            return null;
        }

        $briefNorm = $this->normalizeText($brief);
        if ($briefNorm === '') {
            return null;
        }

        if (
            str_contains($briefNorm, 'ultima immagine')
            || str_contains($briefNorm, 'ultima foto')
            || str_contains($briefNorm, 'latest image')
            || str_contains($briefNorm, 'last image')
        ) {
            $latest = reset($validPaths);
            if (is_string($latest) && $latest !== '') {
                return [
                    'use_brand' => true,
                    'path' => $latest,
                    'reason' => 'brief_latest_image_hint',
                    'confidence' => 1.0,
                ];
            }
        }

        $tokens = $this->briefMeaningfulTokens($briefNorm);
        if (empty($tokens)) {
            return null;
        }

        $assets = collect((array) data_get($meta, 'brand_assets', []))
            ->filter(fn ($a) => is_array($a) && (($a['kind'] ?? null) === 'image') && !empty($a['path']))
            ->keyBy(fn ($a) => (string) ($a['path'] ?? ''));

        $bestPath = null;
        $bestScore = 0;

        foreach ($validPaths as $path) {
            $asset = $assets->get($path);
            $name = is_array($asset) ? ((string) ($asset['original_name'] ?? '')) : '';
            $hay = $this->normalizeText($name . ' ' . basename((string) $path));
            if ($hay === '') {
                continue;
            }

            $score = 0;
            foreach ($tokens as $t) {
                if (str_contains($hay, $t)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = (string) $path;
            }
        }

        if ($bestPath !== null && $bestScore >= 2) {
            return [
                'use_brand' => true,
                'path' => $bestPath,
                'reason' => 'brief_keyword_match',
                'confidence' => min(0.95, 0.45 + ($bestScore * 0.08)),
            ];
        }

        return null;
    }

    private function selectBrandImageByVision(ContentItem $item, array $validPaths, OpenAiService $openAi): ?array
    {
        if (count($validPaths) < 2) {
            return null;
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $brief = trim((string) data_get($meta, 'manual_brief', ''));
        if ($brief === '') {
            return null;
        }

        $public = Storage::disk('public');
        $assetsByPath = collect((array) data_get($meta, 'brand_assets', []))
            ->filter(fn ($a) => is_array($a) && (($a['kind'] ?? null) === 'image') && !empty($a['path']))
            ->keyBy(fn ($a) => (string) ($a['path'] ?? ''));

        $candidates = [];
        foreach (array_slice($validPaths, 0, 6) as $path) {
            if (!$public->exists($path)) {
                continue;
            }
            $asset = $assetsByPath->get($path);
            $candidates[] = [
                'path' => (string) $path,
                'original_name' => is_array($asset) ? (string) ($asset['original_name'] ?? '') : '',
                'absolute_path' => $public->path($path),
            ];
        }

        if (count($candidates) < 2) {
            return null;
        }

        $selected = $openAi->selectBestBrandImageForBrief($brief, $candidates);
        if (!is_array($selected)) {
            return null;
        }

        $selectedPath = trim((string) ($selected['path'] ?? ''));
        if ($selectedPath === '' || !in_array($selectedPath, $validPaths, true)) {
            return null;
        }

        $confidence = (float) ($selected['confidence'] ?? 0.0);
        if ($confidence < 0.35) {
            return null;
        }

        return [
            'use_brand' => true,
            'path' => $selectedPath,
            'reason' => 'brief_visual_match',
            'confidence' => round(max(0.0, min(1.0, $confidence)), 2),
            'model_reason' => Str::limit((string) ($selected['reason'] ?? ''), 180, ''),
        ];
    }

    private function briefMeaningfulTokens(string $normalized): array
    {
        $stop = [
            'con', 'senza', 'della', 'delle', 'degli', 'dello', 'dell', 'dalla', 'dalle', 'dallo',
            'dove', 'come', 'questa', 'questo', 'quello', 'quella', 'immagine', 'foto', 'post',
            'social', 'contenuto', 'crea', 'creami', 'genera', 'genera', 'ultim', 'ultima', 'latest',
            'image', 'logo', 'dietro', 'sopra', 'sotto', 'solo', 'anche', 'molto', 'poco', 'voglio',
        ];
        $stopLookup = array_fill_keys($stop, true);

        $parts = preg_split('/\s+/', $normalized) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (isset($stopLookup[$p])) {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private function totalItemsInPlan(ContentItem $item): int
    {
        return (int) ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->count();
    }

    private function positionInPlan(ContentItem $item): int
    {
        $ids = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $position = array_search($item->id, $ids, true);
        if ($position === false) {
            return max(0, count($ids) - 1);
        }
        return (int) $position;
    }

    private function usedBrandImagePathsInPlan(int $tenantId, int $contentPlanId, int $excludeItemId): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('content_plan_id', $contentPlanId)
            ->where('id', '!=', $excludeItemId)
            ->whereNotNull('ai_meta')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['ai_meta']);

        $used = [];
        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $path = data_get($meta, 'image_generation.brand_source_path');
            if (is_string($path) && $path !== '') {
                $used[] = $path;
            }
        }

        return array_values(array_unique($used));
    }

    private function planAlreadyUsedBrandImage(ContentItem $item): bool
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_meta')
            ->get(['ai_meta']);

        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            if ((string) data_get($meta, 'image_generation.source', '') === 'brand_image_edit') {
                return true;
            }
        }

        return false;
    }

    private function computeImageHashFromBytes(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }

        $thumb = imagecreatetruecolor(8, 8);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));

        $vals = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $vals[] = (int) round(($r + $g + $b) / 3);
            }
        }
        $avg = array_sum($vals) / max(1, count($vals));

        $bits = '';
        foreach ($vals as $v) {
            $bits .= ($v >= $avg) ? '1' : '0';
        }

        imagedestroy($thumb);
        imagedestroy($img);

        return $bits;
    }

    private function loadRecentImageHashes(int $tenantId, int $excludeItemId, int $limit = 24): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $excludeItemId)
            ->whereNotNull('ai_image_path')
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['ai_image_path', 'ai_meta']);

        $out = [];
        $disk = Storage::disk('public');
        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $hash = data_get($meta, 'image_generation.image_hash');
            if (is_string($hash) && strlen($hash) === 64) {
                $out[] = $hash;
                continue;
            }

            $path = (string) $row->ai_image_path;
            if ($path === '' || !$disk->exists($path)) {
                continue;
            }
            $bytes = $disk->get($path);
            $computed = $this->computeImageHashFromBytes($bytes);
            if ($computed !== null) {
                $out[] = $computed;
            }
        }

        return array_values(array_unique($out));
    }

    private function maxImageHashSimilarity(?string $hash, array $otherHashes): float
    {
        if (!$hash || empty($otherHashes)) {
            return 0.0;
        }
        $max = 0.0;
        foreach ($otherHashes as $other) {
            if (!is_string($other) || strlen($other) !== strlen($hash)) {
                continue;
            }
            $distance = 0;
            for ($i = 0; $i < strlen($hash); $i++) {
                if ($hash[$i] !== $other[$i]) {
                    $distance++;
                }
            }
            $sim = 1.0 - ($distance / max(1, strlen($hash)));
            if ($sim > $max) {
                $max = $sim;
            }
        }
        return $max;
    }

    private function loadBrandAssetsFromDb(int $tenantId): array
    {
        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->orderByDesc('id')
            ->limit(48)
            ->get(['id', 'kind', 'path', 'original_name', 'mime'])
            ->map(fn ($asset) => [
                'id' => (int) $asset->id,
                'kind' => (string) $asset->kind,
                'path' => (string) $asset->path,
                'original_name' => (string) ($asset->original_name ?? ''),
                'mime' => (string) ($asset->mime ?? ''),
            ])
            ->values()
            ->all();
    }

    private function mergeBrandAssets(array $fromMeta, array $fromDb): array
    {
        $all = array_merge($fromMeta, $fromDb);
        $out = [];
        $seen = [];
        foreach ($all as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $kind = trim((string) ($asset['kind'] ?? ''));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($kind === '' || $path === '') {
                continue;
            }
            $key = $kind . '|' . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'id' => isset($asset['id']) ? (int) $asset['id'] : null,
                'kind' => $kind,
                'path' => $path,
                'original_name' => (string) ($asset['original_name'] ?? ''),
                'mime' => (string) ($asset['mime'] ?? ''),
            ];
        }
        return $out;
    }

    private function uniqueAssets(array $assets): array
    {
        $out = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $path = (string) ($asset['path'] ?? '');
            $type = (string) ($asset['type'] ?? '');
            if ($path === '') {
                continue;
            }
            $key = $type . '|' . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['type' => $type ?: 'asset', 'path' => $path];
        }
        return $out;
    }

    private function attachBrandVideoReference(ContentItem $item): void
    {
        $format = strtolower((string) ($item->format ?? ''));
        if (!in_array($format, ['reel', 'story', 'video'], true)) {
            return;
        }

        $videoPath = BrandAsset::query()
            ->where('tenant_id', $item->tenant_id)
            ->whereNull('content_plan_id')
            ->where('kind', 'video')
            ->orderBy('id')
            ->value('path');

        if (!is_string($videoPath) || trim($videoPath) === '') {
            return;
        }

        $assets = is_array($item->assets) ? $item->assets : [];
        $assets[] = ['type' => 'brand_video', 'path' => $videoPath];
        $item->assets = $this->uniqueAssets($assets);

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['video_reference'] = [
            'path' => $videoPath,
            'kind' => 'brand_video',
        ];
        $item->ai_meta = $meta;
    }
}

