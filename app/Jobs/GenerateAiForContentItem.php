<?php

namespace App\Jobs;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Services\AI\AiProviderMatrixService;
use App\Services\AI\ContentAlignmentService;
use App\Services\AI\TenantContentIntelligenceService;
use App\Services\MemoryBuilderService;
use App\Support\ImagePromptRealismGuard;
use App\Services\KlingService;
use App\Services\NanoBananaService;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\OpenAiService;
use App\Services\RunwayService;
use App\Services\SpeechSynthesisService;
use App\Support\ImageProviderResolver;
use App\Support\PublicMediaUrl;
use App\Support\VideoProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateAiForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(
        OpenAiService $openAi,
        RunwayService $runway,
        KlingService $kling,
        NanoBananaService $nanoBanana,
        MemoryBuilderService $memoryBuilder,
        TenantContentIntelligenceService $tenantContentIntelligence,
        AiProviderMatrixService $aiProviderMatrixService,
        ContentAlignmentService $contentAlignment,
        WorkspaceNotificationService $workspaceNotifications,
        SpeechSynthesisService $speechSynthesis
    ): void
    {
        $item = ContentItem::query()->with('plan')->findOrFail($this->contentItemId);
        $strictAssetMode = (bool) config('generation.strict_asset_mode', true);

        $item->ai_status = 'pending';
        $item->ai_error = null;
        $item->save();

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $liveBrandAssets = $this->loadBrandAssetsFromDb((int) $item->tenant_id);
        $meta['brand_assets'] = $this->mergeBrandAssets((array) data_get($meta, 'brand_assets', []), $liveBrandAssets);
        $strategy = data_get($meta, 'strategy', $item->plan?->strategy ?? []);
        $itemBrain = data_get($meta, 'item_brain', []);
        $tenantProfile = data_get($meta, 'tenant_profile', data_get($meta, 'brand', []));
        $memorySummary = $memoryBuilder->buildForTenant((int) $item->tenant_id, 40);
        $activeFeedbackRequest = $this->normalizeFeedbackRequest((array) data_get($meta, 'feedback_loop.active_request', []));
        $assetVariables = $this->resolveAssetVariableContext($meta, $strategy);
        $assetIdentity = $this->resolveAssetIdentityContext($meta, $assetVariables);
        $briefSeed = trim((string) ($item->caption ?: data_get($meta, 'manual_brief', $item->title ?: '')));
        $tenantIntelligence = $tenantContentIntelligence->buildForGeneration(
            (int) $item->tenant_id,
            $briefSeed,
            (string) $item->format,
            $item->platforms()
        );
        $providerMatrix = $aiProviderMatrixService->resolve($meta);
        $meta['memory_summary'] = $memorySummary;
        $meta['image_provider'] = $this->resolveImageProvider($meta);
        $meta['asset_variables'] = $assetVariables;
        $meta['asset_variables_catalog'] = (array) ($assetVariables['catalog'] ?? []);
        $meta['asset_identity'] = $assetIdentity;
        $meta['knowledge_pack'] = (array) ($tenantIntelligence['knowledge_pack'] ?? []);
        $meta['examples'] = (array) ($tenantIntelligence['examples'] ?? []);
        $meta['negative_examples'] = (array) ($tenantIntelligence['negative_examples'] ?? []);
        $meta['feedback_signals'] = (array) ($tenantIntelligence['feedback_signals'] ?? []);
        $meta['provider_matrix'] = $providerMatrix;
        $meta['strategy_snapshot'] = [
            'strategy_id' => data_get($strategy, 'strategy_id'),
            'strategy_updated_at' => data_get($strategy, 'strategy_updated_at'),
            'strategy_locked' => (bool) data_get($strategy, 'strategy_locked', false),
            'analysis_framework' => (array) data_get($strategy, 'analysis_framework', []),
            'visual_system' => (array) data_get($strategy, 'visual_system', []),
            'publishing_system' => (array) data_get($strategy, 'publishing_system', []),
            'strategy_notes' => (string) data_get($strategy, 'strategy_notes', ''),
            'captured_at' => now()->toDateTimeString(),
        ];
        $item->ai_meta = $meta;
        $item->save();

        if ($this->isDemoMode()) {
            $this->applyDemoPreset($item, $tenantProfile, $itemBrain, $meta);
            $this->notifyAiSuccess($item, $workspaceNotifications);
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
                'strategy_blueprint' => [
                    'analysis_framework' => (array) data_get($strategy, 'analysis_framework', []),
                    'visual_system' => (array) data_get($strategy, 'visual_system', []),
                    'publishing_system' => (array) data_get($strategy, 'publishing_system', []),
                    'notes' => (string) data_get($strategy, 'strategy_notes', ''),
                ],
                'item_brain' => $itemBrain,
                'manual_brief' => $briefSeed,
                'memory_summary' => $memorySummary,
                'knowledge_pack' => (array) data_get($meta, 'knowledge_pack', []),
                'examples' => (array) data_get($meta, 'examples', []),
                'negative_examples' => (array) data_get($meta, 'negative_examples', []),
                'feedback_signals' => (array) data_get($meta, 'feedback_signals', []),
                'provider_matrix' => $providerMatrix,
                'feedback_loop' => [
                    'active_request' => $activeFeedbackRequest,
                    'latest_feedback' => (array) data_get($meta, 'feedback_loop.latest_feedback', []),
                    'tenant_feedback' => (array) data_get($memorySummary, 'feedback_summary', []),
                ],
                'social_publication_context' => $this->buildSocialPublicationContext(
                    $item,
                    $itemBrain,
                    $planTitles,
                    $planCaptions
                ),
                'asset_variables' => $assetVariables,
                'asset_identity' => $assetIdentity,
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
            $bestAlignmentScore = 0.0;
            $bestCombinedScore = -1.0;
            $bestAlignmentReview = null;
            $similarityFeedback = null;
            $alignmentFeedback = null;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $iterContext = $context;
                $generationGuard = [];

                if ($similarityFeedback !== null) {
                    $generationGuard['similarity'] = [
                        'retry' => $attempt + 1,
                        'reason' => 'La caption precedente era troppo simile a contenuti esistenti.',
                        'most_similar_caption' => $similarityFeedback['text'],
                        'similarity_score' => $similarityFeedback['score'],
                        'instruction' => 'Crea hook, angolo narrativo e CTA chiaramente diversi, restando coerente con la strategia.',
                    ];
                }

                if ($alignmentFeedback !== null) {
                    $generationGuard['alignment'] = array_merge($alignmentFeedback, [
                        'retry' => $attempt + 1,
                    ]);
                }

                if (!empty($generationGuard)) {
                    $iterContext['generation_guard'] = $generationGuard;
                }

                $gen = $openAi->generateContent($iterContext);
                $caption = trim((string) ($gen['caption'] ?? ''));
                $score = $this->maxTextSimilarity($caption, $comparisonTexts);
                $alignmentReview = null;
                $alignmentScore = 1.0;

                if ((bool) config('generation.alignment_enabled', true)) {
                    $alignmentReview = $contentAlignment->gradeTextDraft($iterContext, $gen, $providerMatrix);
                    $alignmentScore = max(0.0, min(1.0, (float) ($alignmentReview['overall_score'] ?? 0.0)));
                }

                $combinedScore = round(((1 - min(1.0, $score)) * 0.38) + ($alignmentScore * 0.62), 4);
                if ($combinedScore > $bestCombinedScore) {
                    $bestCombinedScore = $combinedScore;
                    $bestScore = $score;
                    $bestAlignmentScore = $alignmentScore;
                    $bestGen = $gen;
                    $bestAlignmentReview = $alignmentReview;
                }

                if ($score < 0.72 && !((bool) ($alignmentReview['should_retry'] ?? false))) {
                    $bestGen = $gen;
                    $bestScore = $score;
                    $bestAlignmentScore = $alignmentScore;
                    $bestAlignmentReview = $alignmentReview;
                    break;
                }

                $similarityFeedback = $score >= 0.72 ? [
                    'score' => $score,
                    'text' => $this->closestText($caption, $comparisonTexts),
                ] : null;
                $alignmentFeedback = is_array($alignmentReview) && !empty($alignmentReview['feedback'])
                    ? (array) $alignmentReview['feedback']
                    : null;
            }

            $gen = $bestGen ?? [];
            $textAlignmentReview = is_array($bestAlignmentReview) ? $bestAlignmentReview : null;

            $item->ai_caption = $gen['caption'] ?? $item->ai_caption;
            $item->ai_hashtags = $gen['hashtags'] ?? [];
            $item->ai_cta = $gen['cta'] ?? ($itemBrain['cta'] ?? $item->ai_cta);
            $item->ai_image_prompt = $gen['image_prompt'] ?? $item->ai_image_prompt;
            $nextMeta = array_merge($meta, [
                'text_similarity_score' => round($bestScore, 4),
                'text_alignment_score' => round($bestAlignmentScore, 4),
                'text_alignment_review' => $textAlignmentReview,
                'text_uniqueness_checked_at' => now()->toDateTimeString(),
                'text_provider_last_used' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
                'grader_provider_last_used' => (string) data_get($providerMatrix, 'grader.provider', 'openai'),
                'provider_matrix' => $providerMatrix,
            ]);
            $generatedVideoPrompt = trim((string) ($gen['video_prompt'] ?? ''));
            if ($generatedVideoPrompt !== '') {
                $nextMeta['video_prompt'] = $generatedVideoPrompt;
            }
            $generatedVoiceover = trim((string) ($gen['voiceover'] ?? ''));
            if ($generatedVoiceover !== '') {
                $nextMeta['video_voiceover'] = $generatedVoiceover;
            }
            $normalizedReelBlueprint = $this->normalizeReelBlueprint(
                blueprint: is_array($gen['reel_blueprint'] ?? null) ? $gen['reel_blueprint'] : [],
                item: $item,
                meta: $meta,
                assetVariables: $assetVariables,
                videoPrompt: $generatedVideoPrompt !== '' ? $generatedVideoPrompt : trim((string) ($gen['image_prompt'] ?? ''))
            );
            if (!empty($normalizedReelBlueprint)) {
                $nextMeta['reel_blueprint'] = $normalizedReelBlueprint;
            }
            $item->ai_meta = $nextMeta;
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
            if ($strictAssetMode && !((bool) ($brandDecision['use_brand'] ?? false))) {
                throw new \RuntimeException('Strict mode: nessuna immagine brand valida trovata per avviare la generazione.');
            }
            $selectedBrandImage = $brandDecision['path'] ?? null;
            $selectedBrandImagePaths = array_values(array_filter(array_map(
                'strval',
                (array) ($brandDecision['paths'] ?? ($selectedBrandImage ? [$selectedBrandImage] : []))
            )));
            if (is_string($selectedBrandImage) && $selectedBrandImage !== '' && !in_array($selectedBrandImage, $selectedBrandImagePaths, true)) {
                array_unshift($selectedBrandImagePaths, $selectedBrandImage);
            }
            $selectedBrandImagePaths = $this->filterReferenceImagePaths(array_values(array_unique($selectedBrandImagePaths)));
            $selectedBrandImagePaths = $this->stabilizeReferencePathsForFeedback(
                $selectedBrandImagePaths,
                $activeFeedbackRequest,
                $assetVariables
            );
            $selectedBrandImage = $selectedBrandImagePaths[0] ?? $selectedBrandImage;

            if ($prompt === '') {
                $brandName = data_get($tenantProfile, 'business_name', 'Brand');
                $industry = data_get($tenantProfile, 'industry', '');
                $palette = data_get($strategy, 'brand_references.palette', '');
                $logoPath = data_get($strategy, 'brand_references.logo_path', '');
                $visualRules = data_get($itemBrain, 'image_direction', 'Visual coerente con il brand.');
                $visualStyle = (string) data_get($strategy, 'visual_system.style', '');
                $visualMood = (string) data_get($strategy, 'visual_system.mood', '');
                $visualDo = (string) data_get($strategy, 'visual_system.visual_do', '');
                $visualDont = (string) data_get($strategy, 'visual_system.visual_dont', '');
                $logoRule = (string) data_get($strategy, 'visual_system.logo_rule', '');
                $analysisGoal = (string) data_get($strategy, 'analysis_framework.primary_goal', '');
                $publishingCadence = (string) data_get($strategy, 'publishing_system.cadence_rule', '');
                $strategyNotes = (string) data_get($strategy, 'strategy_notes', '');
                $assetVariableHint = $this->buildAssetVariablePromptHint($assetVariables);
                $assetIdentityHint = $this->buildAssetIdentityPromptHint($assetIdentity);
                $locationEnvelopeHint = $this->locationEnvelopePreservationInstruction($assetVariables, $selectedBrandImagePaths);
                $feedbackVisualHint = $this->feedbackDrivenImageInstruction(
                    $activeFeedbackRequest,
                    $selectedBrandImagePaths,
                    $assetVariables
                );
                $socialPublicationHint = $this->socialGraphicSystemInstruction($item, $itemBrain);

                $prompt = "Crea un'immagine social premium pronta per Instagram feed per {$brandName}. "
                    . "Settore: {$industry}. "
                    . "Direzione visiva: {$visualRules}. "
                    . "Palette colore suggerita: {$palette}. "
                    . ($analysisGoal !== '' ? "Obiettivo strategico principale: {$analysisGoal}. " : '')
                    . ($visualStyle !== '' ? "Stile visual: {$visualStyle}. " : '')
                    . ($visualMood !== '' ? "Mood visual: {$visualMood}. " : '')
                    . ($visualDo !== '' ? "Regola visual da fare: {$visualDo}. " : '')
                    . ($visualDont !== '' ? "Regola visual da evitare: {$visualDont}. " : '')
                    . ($logoRule !== '' ? "Regola logo: {$logoRule}. " : '')
                    . ($publishingCadence !== '' ? "Coerenza publishing: {$publishingCadence}. " : '')
                    . ($strategyNotes !== '' ? "Note strategiche: {$strategyNotes}. " : '')
                    . ($assetVariableHint !== '' ? "Variabili asset obbligatorie: {$assetVariableHint}. " : '')
                    . ($assetIdentityHint !== '' ? "Regole identitarie del contenuto: {$assetIdentityHint}. " : '')
                    . ($locationEnvelopeHint !== '' ? $locationEnvelopeHint . ' ' : '')
                    . ($feedbackVisualHint !== '' ? $feedbackVisualHint . ' ' : '')
                    . ($socialPublicationHint !== '' ? $socialPublicationHint . ' ' : '')
                    . "Percorso logo di riferimento (solo contesto stilistico): {$logoPath}. "
                    . ($selectedBrandImage ? "Parti dai riferimenti brand forniti e trasformali in un visual editoriale strategico, non in una semplice copia della foto originale. " : "Crea la composizione da zero seguendo la strategia e mantenendo novita rispetto ai post precedenti. ")
                    . "Non generare loghi finti, nome brand scritto, watermark o testo sovraimpresso nell'immagine. "
                    . "Se ÃƒÂ¨ necessario includere testo grafico nell'immagine, usa solo italiano corretto. "
                    . "Stile professionale, coerente con il brand e totalmente in italiano.";

                $item->ai_image_prompt = $prompt;
                $item->save();
            }

            $prompt = $this->augmentPromptForInstagramImageExecution(
                $item,
                $prompt,
                $selectedBrandImagePaths,
                $assetVariables,
                $activeFeedbackRequest
            );
            if ($prompt !== (string) ($item->ai_image_prompt ?? '')) {
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
            $lastImageAlignmentReview = null;
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
                    nanoBanana: $nanoBanana,
                    runway: $runway,
                    kling: $kling,
                    item: $item,
                    prompt: $prompt,
                    selectedBrandImageAbs: $selectedBrandImageAbs,
                    selectedBrandImageAbsList: $selectedBrandImageAbsList,
                    selectedBrandImagePath: $selectedBrandImage,
                    selectedBrandImagePaths: $selectedBrandImagePaths,
                    logoRuntime: $logoRuntime,
                    activeFeedbackRequest: $activeFeedbackRequest,
                    brandDecision: $brandDecision
                );

                $videoPath = trim((string) ($videoResult['video_path'] ?? ''));
                $thumbPath = trim((string) ($videoResult['thumbnail_path'] ?? ''));

                if ($videoPath !== '') {
                    $audioAttach = $this->maybeAttachAudioTrackToVideo(
                        item: $item,
                        videoPath: $videoPath,
                        speechSynthesis: $speechSynthesis
                    );
                    if ((bool) ($audioAttach['applied'] ?? false) && !empty($audioAttach['video_path'])) {
                        $videoPath = (string) $audioAttach['video_path'];
                    }

                    $gridPreviewPath = $this->createLocalImagePlaceholder($item, $tenantProfile);
                    if (is_string($gridPreviewPath) && trim($gridPreviewPath) !== '') {
                        $item->ai_image_path = $gridPreviewPath;
                    } elseif ($thumbPath !== '') {
                        $item->ai_image_path = $thumbPath;
                    }

                    $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
                    $metaNow['video_generation'] = [
                        'source' => (string) ($videoResult['source'] ?? 'sora_video'),
                        'provider' => (string) ($videoResult['provider'] ?? data_get($metaNow, 'video_provider', 'openai')),
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
                        'alignment_review' => $videoResult['reference_validation'] ?? null,
                        'composition_reference' => $videoResult['composition_reference'] ?? null,
                        'generation_attempts' => (int) ($videoResult['generation_attempts'] ?? 1),
                        'request_summary' => $videoResult['request_summary'] ?? null,
                        'reference_input_summary' => $videoResult['reference_input_summary'] ?? null,
                        'audio' => $audioAttach,
                        'fallback' => $imageSourceFallback,
                        'provider_fallback' => $videoResult['provider_fallback'] ?? null,
                        'generated_at' => now()->toDateTimeString(),
                    ];
                    $metaNow['video_provider_requested'] = VideoProviderResolver::normalize((string) data_get($metaNow, 'video_provider', ''));
                    $metaNow['video_provider_last_used'] = (string) ($videoResult['provider'] ?? data_get($metaNow, 'video_provider', 'openai'));
                    $item->ai_meta = $metaNow;

                    $assets = is_array($item->assets) ? $item->assets : [];
                    foreach ($selectedBrandImagePaths as $brandPath) {
                        $assets[] = ['type' => 'brand_source', 'path' => $brandPath];
                    }
                    if ($logoRequested && $logoScenePath) {
                        $assets[] = ['type' => 'brand_logo', 'path' => $logoScenePath];
                    }
                    $assets[] = ['type' => 'ai_generated_video', 'path' => $videoPath];
                    if (!empty($audioAttach['audio_path']) && is_string($audioAttach['audio_path'])) {
                        $assets[] = ['type' => 'ai_generated_audio', 'path' => (string) $audioAttach['audio_path']];
                    }
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
                    $attemptPrompt .= ' ' . $this->instagramVisualOutputInstruction($item);
                    $attemptPrompt .= ' ' . $this->multiReferenceBlendInstruction($selectedBrandImagePaths);
                    $attemptPrompt .= ' ' . $this->locationEnvelopePreservationInstruction($assetVariables, $selectedBrandImagePaths);

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
                                    $attemptPrompt .= ' Unifica i riferimenti multipli in un unica scena plausibile da shooting editoriale o campagna social, senza collage o split-screen.';
                                } else {
                                    $attemptPrompt .= ' Mantieni il DNA visivo riconoscibile dell immagine brand fornita (scena, oggetti, inquadratura) adattandola alla strategia del post.';
                                }
                            } else {
                                $attemptPrompt .= ' Crea una scena completa da zero, coerente con il brief e con il brand.';
                            }

                            $img = $this->generateImageEditWithProvider(
                                provider: $this->resolveImageProvider((array) ($item->ai_meta ?? [])),
                                prompt: $attemptPrompt,
                                editPaths: $editPaths,
                                openAi: $openAi,
                                nanoBanana: $nanoBanana
                            );
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
                            $img = $this->generateImageTextWithProvider(
                                provider: $this->resolveImageProvider((array) ($item->ai_meta ?? [])),
                                prompt: $attemptPrompt,
                                openAi: $openAi,
                                nanoBanana: $nanoBanana
                            );
                            $imageSourceMode = 'text_to_image';
                            $brandSourcesUsed = [];
                            $brandSourceUsed = null;
                            $metaFallback = is_array($item->ai_meta) ? $item->ai_meta : [];
                            $metaFallback['image_edit_error'] = Str::limit($editError->getMessage(), 240, '');
                            $metaFallback['image_edit_error_at'] = now()->toDateTimeString();
                            $metaFallback['image_provider'] = $this->resolveImageProvider($metaFallback);
                            $item->ai_meta = $metaFallback;
                            $item->save();
                        }
                    } else {
                        $img = $this->generateImageTextWithProvider(
                            provider: $this->resolveImageProvider((array) ($item->ai_meta ?? [])),
                            prompt: $attemptPrompt,
                            openAi: $openAi,
                            nanoBanana: $nanoBanana
                        );
                        $imageSourceMode = 'text_to_image';
                        $brandSourcesUsed = [];
                        $brandSourceUsed = null;
                    }
                    $candidateBytes = base64_decode((string) ($img['b64'] ?? ''), true);

                    if ($candidateBytes === false || $candidateBytes === '') {
                        continue;
                    }

                    $currentImageAlignmentReview = null;
                    if ((bool) config('generation.alignment_image_reference_validation', true) && !empty($selectedBrandImageAbsList)) {
                        $tmpImagePath = tempnam(sys_get_temp_dir(), 'align-img-');
                        if (is_string($tmpImagePath) && $tmpImagePath !== '') {
                            @file_put_contents($tmpImagePath, $candidateBytes);
                            $currentImageAlignmentReview = $contentAlignment->validateGeneratedImageCandidate(
                                $briefSeed,
                                $tmpImagePath,
                                array_slice($selectedBrandImageAbsList, 0, 4),
                                $providerMatrix
                            );
                            @unlink($tmpImagePath);
                        }
                    }

                    $alignmentRejected = is_array($currentImageAlignmentReview)
                        && !((bool) ($currentImageAlignmentReview['all_present'] ?? true))
                        && (float) ($currentImageAlignmentReview['confidence'] ?? 0.0) >= (float) (config('generation.alignment_image_reference_min_confidence') ?: 0.55);
                    if ($alignmentRejected && $imgAttempt < 1) {
                        continue;
                    }

                    $candidateHash = $this->computeImageHashFromBytes($candidateBytes);
                    if ($candidateHash === null) {
                        $bytes = $candidateBytes;
                        $finalHash = null;
                        $lastImageAlignmentReview = $currentImageAlignmentReview;
                        break;
                    }

                    $similarity = $this->maxImageHashSimilarity($candidateHash, $recentImageHashes);
                    if ($similarity < 0.9 || $imgAttempt === 1) {
                        $bytes = $candidateBytes;
                        $finalHash = $candidateHash;
                        $lastImageAlignmentReview = $currentImageAlignmentReview;
                        break;
                    }
                }

                if (is_string($bytes) && $bytes !== '') {
                    $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
                    Storage::disk('public')->put($filename, $bytes);
                    $item->ai_image_path = $filename;
                    $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
                    $resolvedImageProvider = $this->resolveImageProvider($metaNow);
                    $metaNow['image_provider'] = $resolvedImageProvider;
                    $metaNow['image_generation'] = [
                        'provider' => $resolvedImageProvider,
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
                        'alignment_review' => $lastImageAlignmentReview,
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
            $isVideoFailure = $this->isVideoFormat($item);
            $errorKey = $isVideoFailure ? 'video_error' : 'image_error';
            $errorAtKey = $errorKey . '_at';
            $meta[$errorKey] = $e->getMessage();
            $meta[$errorAtKey] = now()->toDateTimeString();

            if ($isVideoFailure) {
                $meta['video_provider_requested'] = $this->resolveVideoProvider($meta);
                $item->ai_image_path = null;
            } else {
                $meta['image_provider'] = $this->resolveImageProvider($meta);
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
            }

            $item->ai_meta = $meta;
            $item->save();

            Log::warning('GenerateAiForContentItem visual generation failed', [
                'content_item_id' => $item->id,
                'mode' => $isVideoFailure ? 'video' : 'image',
                'error' => $e->getMessage(),
            ]);
        }

        // Overlay usato come garanzia quando il brief chiede il logo e il modello non ha potuto editarlo correttamente.

        if ($strictAssetMode && !$this->hasGeneratedVisualOutput($item)) {
            $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
            $errorMetaKey = $this->isVideoFormat($item) ? 'video_error' : 'image_error';
            $baseError = trim((string) ($item->ai_error ?? ''));
            if ($baseError === '') {
                $baseError = trim((string) data_get($metaNow, $errorMetaKey, ''));
            }

            $item->ai_status = 'error';
            $item->ai_error = $baseError !== ''
                ? $baseError . ' | STRICT_MODE_NO_VISUAL_OUTPUT'
                : 'STRICT_MODE_NO_VISUAL_OUTPUT';
            $item->ai_generated_at = now();
            $item->save();
            $this->notifyAiFailure($item, $workspaceNotifications, (string) $item->ai_error);
            return;
        }

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $this->markFeedbackRequestAsApplied($item);
        $item->save();
        $this->notifyAiSuccess($item, $workspaceNotifications);
    }

    public function failed(Throwable $e): void
    {
        $item = ContentItem::query()->find($this->contentItemId);
        if (!$item) {
            return;
        }

        $item->ai_status = 'error';
        if (trim((string) $item->ai_error) === '') {
            $item->ai_error = 'JOB: ' . $e->getMessage();
        }
        $item->save();

        app(WorkspaceNotificationService::class)->notifyTenant(
            (int) $item->tenant_id,
            'Generazione contenuto non riuscita',
            $this->contentNotificationLabel($item) . ' non e stato generato correttamente. Controlla il dettaglio tecnico e riprova.',
            [
                'level' => 'error',
                'icon' => 'ai-error',
                'action_url' => route('posts.edit', $item),
                'action_label' => 'Apri contenuto',
                'context_type' => 'content_item',
                'context_id' => (int) $item->id,
                'meta' => [
                    'ai_error' => Str::limit((string) $item->ai_error, 220, ''),
                ],
            ]
        );
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
        $this->markFeedbackRequestAsApplied($item);
        $item->save();
    }

    private function markFeedbackRequestAsApplied(ContentItem $item): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $active = data_get($meta, 'feedback_loop.active_request');

        if (!is_array($active) || empty($active)) {
            return;
        }

        $active['applied_at'] = now()->toDateTimeString();
        $meta['feedback_loop']['last_applied'] = $active;
        $meta['feedback_loop']['active_request'] = null;
        $item->ai_meta = $meta;
    }

    private function notifyAiSuccess(ContentItem $item, WorkspaceNotificationService $workspaceNotifications): void
    {
        $workspaceNotifications->notifyTenant(
            (int) $item->tenant_id,
            'Contenuto pronto',
            $this->contentNotificationLabel($item) . ' e pronto da rivedere o approvare.',
            [
                'level' => 'success',
                'icon' => 'ai-done',
                'action_url' => route('posts.edit', $item),
                'action_label' => 'Apri contenuto',
                'context_type' => 'content_item',
                'context_id' => (int) $item->id,
                'meta' => [
                    'format' => (string) $item->format,
                    'platform' => (string) $item->platform,
                ],
            ]
        );
    }

    private function notifyAiFailure(
        ContentItem $item,
        WorkspaceNotificationService $workspaceNotifications,
        string $reason
    ): void {
        $workspaceNotifications->notifyTenant(
            (int) $item->tenant_id,
            'Generazione contenuto non riuscita',
            $this->contentNotificationLabel($item) . ' ha richiesto un intervento. Puoi rigenerarlo o correggere il prompt.',
            [
                'level' => 'error',
                'icon' => 'ai-error',
                'action_url' => route('posts.edit', $item),
                'action_label' => 'Apri contenuto',
                'context_type' => 'content_item',
                'context_id' => (int) $item->id,
                'meta' => [
                    'ai_error' => Str::limit($reason, 220, ''),
                ],
            ]
        );
    }

    private function contentNotificationLabel(ContentItem $item): string
    {
        $title = trim((string) ($item->title ?: $item->content_angle ?: 'Contenuto'));

        return '"' . Str::limit($title, 70, '') . '"';
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
                'caption' => "Uno degli errori piÃƒÂ¹ comuni negli affitti brevi ÃƒÂ¨ aggiornare i prezzi solo a mano. {$brand} automatizza tariffe e disponibilitÃƒÂ  in base alla domanda reale, eventi locali e storico prenotazioni. Risultato: piÃƒÂ¹ margine e meno camere ferme.",
                'hashtags' => ['#Hostup', '#AffittiBrevi', '#RevenueManagement', '#PropertyManagement', '#Automazione'],
                'cta' => "Vuoi vedere il flusso completo in azione? {$ctaDefault}",
                'image_prompt' => "Dashboard moderna di revenue management per affitti brevi, stile pulito tech, palette brand, scena realistica senza testo.",
            ],
            [
                'title' => 'Canali OTA allineati in tempo reale',
                'caption' => "Sincronizzare manualmente Booking, Airbnb e sito diretto crea overbooking e perdita di tempo. Con {$brand} il calendario resta coerente su tutti i canali: disponibilitÃƒÂ , restrizioni e regole vengono aggiornate automaticamente.",
                'hashtags' => ['#Hostup', '#ChannelManager', '#AirbnbHost', '#BookingCom', '#ShortTermRental'],
                'cta' => "Se vuoi, ti mostriamo in 10 minuti come configurarlo sul tuo portfolio.",
                'image_prompt' => "Interfaccia channel manager multi-canale con card OTA, look future-tech, senza watermark e senza testo.",
            ],
            [
                'title' => 'Meno operativitÃƒÂ , piÃƒÂ¹ controllo',
                'caption' => "La gestione efficace non ÃƒÂ¨ fare tutto a mano, ma avere regole chiare e automazioni affidabili. {$brand} riduce attivitÃƒÂ  ripetitive e ti lascia tempo per decisioni strategiche: occupazione, pricing e qualitÃƒÂ  del servizio.",
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
                'caption' => "Standardizzare i processi fa la differenza quando il numero di annunci cresce. {$brand} applica template e regole ripetibili per velocizzare operazioni quotidiane e mantenere qualitÃƒÂ  costante.",
                'hashtags' => ['#Hostup', '#Processi', '#PropertyOps', '#ScalabilitÃƒÂ ', '#DigitalHospitality'],
                'cta' => "Vuoi una checklist pronta per partire? Te la condividiamo.",
                'image_prompt' => "Vista workflow operativo per property management, cards ordinate e look minimal futuristico.",
            ],
            [
                'title' => 'Setup rapido per team piccoli',
                'caption' => "Anche con un team ridotto puoi gestire in modo professionale: meno tool scollegati, piÃƒÂ¹ controllo centralizzato. {$brand} organizza attivitÃƒÂ , prioritÃƒÂ  e pubblicazione contenuti in un unico flusso chiaro.",
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
            . "Deve sembrare un post social vero, pensato per il feed e non una foto corporate generica. "
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
        NanoBananaService $nanoBanana,
        RunwayService $runway,
        KlingService $kling,
        ContentItem $item,
        string $prompt,
        ?string $selectedBrandImageAbs,
        array $selectedBrandImageAbsList,
        ?string $selectedBrandImagePath,
        array $selectedBrandImagePaths,
        array $logoRuntime,
        array $activeFeedbackRequest,
        array $brandDecision
    ): array {
        $logoRequested = (bool) ($logoRuntime['requested'] ?? false);
        $logoMode = (string) ($logoRuntime['mode'] ?? 'scene');
        $logoAbs = isset($logoRuntime['abs']) && is_string($logoRuntime['abs']) ? $logoRuntime['abs'] : null;
        $logoPath = isset($logoRuntime['path']) && is_string($logoRuntime['path']) ? $logoRuntime['path'] : null;

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $briefRaw = trim((string) data_get($meta, 'manual_brief', ''));
        $briefNorm = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        $assetVariables = (array) data_get($meta, 'asset_variables', []);
        $videoProvider = $this->resolveVideoProvider($meta);
        $explicitReferencePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'image_references.selected_paths', [])
        )));
        $variableReferencePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'asset_variables.resolved_asset_paths', [])
        )));
        if (empty($variableReferencePaths)) {
            $variableReferencePaths = collect((array) data_get($meta, 'asset_variables.resolved', []))
                ->flatMap(fn ($row) => is_array($row) ? (array) ($row['asset_paths'] ?? []) : [])
                ->map(fn ($path) => (string) $path)
                ->filter(fn ($path) => $path !== '')
                ->values()
                ->all();
        }
        if (!empty($variableReferencePaths)) {
            $explicitReferencePaths = array_values(array_unique(array_merge($explicitReferencePaths, $variableReferencePaths)));
        }
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
        $videoControlContext = $this->videoSubjectContextText($meta, $briefRaw !== '' ? $briefRaw : $prompt, (string) data_get($meta, 'video_prompt', ''));
        [$imageReferenceAbsPool, $imageReferencePathPool] = $this->prioritizeVideoReferencePoolsForPersonVariable(
            $imageReferenceAbsPool,
            $imageReferencePathPool,
            $assetVariables,
            $meta,
            $videoControlContext
        );
        $locationSequenceMode = $hasExplicitReferences
            && count($imageReferenceAbsPool) >= 2
            && $this->hasProtectedLocationEnvelope($assetVariables, $imageReferencePathPool);
        $mustEnforceExplicitReferences = $hasExplicitReferences && !empty($imageReferenceAbsPool) && !$locationSequenceMode;
        $dualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $videoControlContext, $assetVariables);
        $klingIdentityBoardMode = $videoProvider === 'kling'
            && !$dualSubjectLock
            && $this->shouldUsePersonIdentityReferenceBoard($assetVariables, $imageReferencePathPool);
        $runwayPrimaryAnchorMode = $videoProvider === 'runway'
            && !$dualSubjectLock
            && Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel'
            && count($imageReferenceAbsPool) >= 2;
        $validationReferenceAbsPool = array_values(array_slice($imageReferenceAbsPool, 0, 4));
        $generationReferenceAbsPool = $imageReferenceAbsPool;
        $compositionReference = null;
        $compositionMeta = null;

        if ($locationSequenceMode) {
            $generationReferenceAbsPool = [(string) ($imageReferenceAbsPool[0] ?? '')];
            $compositionMeta = [
                'used' => false,
                'mode' => 'sequential_real_locations',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($klingIdentityBoardMode) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'kling_person_identity_reference_board',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($runwayPrimaryAnchorMode) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'runway_primary_anchor_reference',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($this->shouldUsePersonIdentityReferenceBoard($assetVariables, $imageReferencePathPool)) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'person_identity_reference_board',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($mustEnforceExplicitReferences && count($imageReferenceAbsPool) >= 2) {
            $compositionReference = $this->buildLockedVideoSceneReference(
                openAi: $openAi,
                nanoBanana: $nanoBanana,
                brief: $briefRaw !== '' ? $briefRaw : $prompt,
                prompt: $prompt,
                referenceAbsPaths: $imageReferenceAbsPool,
                assetVariables: $assetVariables
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
            if ($klingIdentityBoardMode) {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = 'kling_person_identity_reference_board';
            } elseif ($runwayPrimaryAnchorMode) {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = 'runway_primary_anchor_reference';
            } elseif (count($generationReferenceAbsPool) > 1) {
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

        if ($videoProvider !== 'kling' && $logoRequested && $logoAbs) {
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
        } elseif ($videoProvider === 'kling' && $logoRequested && $logoAbs) {
            $referenceReason .= '_logo_prompt_only';
        }

        $videoPrompt = $this->buildStrategicVideoPrompt(
            item: $item,
            meta: $meta,
            briefRaw: $briefRaw !== '' ? $briefRaw : $prompt,
            selectedBrandImageAbs: $selectedBrandImageAbs,
            generationReferenceAbsPool: $generationReferenceAbsPool,
            referencePaths: $referencePaths,
            assetVariables: $assetVariables,
            activeFeedbackRequest: $activeFeedbackRequest,
            logoRequested: $logoRequested,
            logoAbs: $logoAbs,
            logoMode: $logoMode,
            locationSequenceMode: $locationSequenceMode,
            mustEnforceExplicitReferences: $mustEnforceExplicitReferences
        );

        $videoOptions = [
            'model' => match ($videoProvider) {
                'runway' => $this->resolveRunwayVideoModel($item, $meta, $assetVariables, $imageReferencePathPool),
                'kling' => (string) (config('kling.model') ?: ''),
                default => (string) (config('openai.video_model') ?: 'sora-2'),
            },
            'seconds' => $this->targetVideoSecondsForFormat($item),
            'size' => $this->targetVideoSizeForFormat($item),
        ];
        $runwayExecutionPrompt = $videoProvider === 'runway'
            ? $this->buildRunwayReelExecutionPrompt($videoPrompt, $item, $meta, $assetVariables)
            : $videoPrompt;
        $klingExecutionPrompt = $videoProvider === 'kling'
            ? $this->buildKlingExecutionPrompt($videoPrompt, $item, $meta, $assetVariables, $activeFeedbackRequest)
            : $videoPrompt;
        $openAiExecutionPrompt = $this->prepareOpenAiVideoPromptForExecution(
            $videoPrompt,
            $briefRaw !== '' ? $briefRaw : $prompt,
            $referencePaths,
            $assetVariables
        );

        if ($videoProvider !== 'kling' && is_string($referenceAbs) && $referenceAbs !== '') {
            $prepared = $this->prepareVideoReferenceForSize($referenceAbs, (string) $videoOptions['size']);
            if ($prepared) {
                $preparedRefPath = $prepared;
                $referenceAbs = $preparedRefPath;
                $referenceReason .= '_normalized_to_size';
            }
        }

        if ($videoProvider === 'kling') {
            try {
                return $this->generateVideoWithKling(
                    kling: $kling,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $prompt,
                    videoPrompt: $klingExecutionPrompt,
                    referenceAbsPool: $generationReferenceAbsPool,
                    referencePaths: $imageReferencePathPool,
                    referenceReason: $referenceReason,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $videoOptions,
                    assetVariables: $assetVariables,
                    activeFeedbackRequest: $activeFeedbackRequest,
                    locationSequenceMode: $locationSequenceMode
                );
            } finally {
                foreach ($tempRefPaths as $tmpPath) {
                    if (is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath)) {
                        @unlink($tmpPath);
                    }
                }
            }
        }

        if ($videoProvider === 'runway') {
            try {
                return $this->generateVideoWithRunway(
                    runway: $runway,
                    openAi: $openAi,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $prompt,
                    videoPrompt: $runwayExecutionPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $referenceReason,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool,
                    validationReferenceAbsPool: $validationReferenceAbsPool,
                    mustEnforceExplicitReferences: $mustEnforceExplicitReferences,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $videoOptions
                );
            } catch (Throwable $runwayError) {
                if (!$this->shouldFallbackFromRunwayToOpenAi($runwayError)) {
                    throw $runwayError;
                }

                try {
                    return $this->generateVideoWithOpenAi(
                        openAi: $openAi,
                        briefRaw: $briefRaw,
                        fallbackPrompt: $prompt,
                        videoPrompt: $this->buildOpenAiVideoFallbackPrompt($openAiExecutionPrompt, $briefRaw, $referencePaths),
                        referenceAbs: $referenceAbs,
                        referencePath: $referencePath,
                        referencePaths: $referencePaths,
                        referenceReason: $referenceReason . '_openai_fallback_after_runway_failure',
                        generationReferenceAbsPool: $generationReferenceAbsPool,
                        imageReferencePathPool: $imageReferencePathPool,
                        validationReferenceAbsPool: $validationReferenceAbsPool,
                        mustEnforceExplicitReferences: $mustEnforceExplicitReferences,
                        compositionMeta: $compositionMeta,
                        brandDecision: $brandDecision,
                        videoOptions: $videoOptions,
                        assetVariables: $assetVariables,
                        providerFallback: [
                            'from' => 'runway',
                            'to' => 'openai',
                            'reason' => Str::limit($runwayError->getMessage(), 220, ''),
                            'at' => now()->toDateTimeString(),
                        ]
                    );
                } catch (Throwable $openAiFallbackError) {
                    if (!$this->shouldFallbackFromOpenAiToSecondaryProvider($openAiFallbackError)) {
                        throw $openAiFallbackError;
                    }

                    $secondaryProviders = array_values(array_filter(
                        $this->secondaryVideoProvidersForOpenAiFailure(!empty($generationReferenceAbsPool)),
                        fn ($provider) => $provider !== 'runway'
                    ));
                    $secondaryFailures = [];

                    foreach ($secondaryProviders as $secondaryProvider) {
                        try {
                            if ($secondaryProvider !== 'kling') {
                                continue;
                            }

                            $result = $this->generateVideoWithKling(
                                kling: $kling,
                                item: $item,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $klingExecutionPrompt,
                                referenceAbsPool: $generationReferenceAbsPool,
                                referencePaths: $imageReferencePathPool,
                                referenceReason: $referenceReason . '_kling_fallback_after_runway_openai_failure',
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions,
                                assetVariables: $assetVariables,
                                activeFeedbackRequest: $activeFeedbackRequest,
                                locationSequenceMode: $locationSequenceMode
                            );
                            $result['provider_fallback'] = [
                                'from' => 'runway',
                                'via' => 'openai',
                                'to' => 'kling',
                                'reason' => Str::limit($openAiFallbackError->getMessage(), 220, ''),
                                'at' => now()->toDateTimeString(),
                            ];

                            return $result;
                        } catch (Throwable $secondaryError) {
                            $secondaryFailures[] = $secondaryProvider . ': ' . Str::limit($secondaryError->getMessage(), 220, '');
                        }
                    }

                    if (!empty($secondaryFailures)) {
                        throw new RuntimeException(
                            $openAiFallbackError->getMessage() . ' | Secondary fallback failures: ' . implode(' | ', $secondaryFailures),
                            previous: $openAiFallbackError
                        );
                    }

                    throw $openAiFallbackError;
                }
            }
        }

        try {
            return $this->generateVideoWithOpenAi(
                openAi: $openAi,
                briefRaw: $briefRaw,
                fallbackPrompt: $prompt,
                videoPrompt: $videoProvider === 'openai' ? $openAiExecutionPrompt : $videoPrompt,
                referenceAbs: $referenceAbs,
                referencePath: $referencePath,
                referencePaths: $referencePaths,
                referenceReason: $referenceReason,
                generationReferenceAbsPool: $generationReferenceAbsPool,
                imageReferencePathPool: $imageReferencePathPool,
                validationReferenceAbsPool: $validationReferenceAbsPool,
                mustEnforceExplicitReferences: $mustEnforceExplicitReferences,
                compositionMeta: $compositionMeta,
                brandDecision: $brandDecision,
                videoOptions: $videoOptions,
                assetVariables: $assetVariables,
                providerFallback: null
            );
        } catch (Throwable $openAiError) {
            if (!$this->shouldFallbackFromOpenAiToSecondaryProvider($openAiError)) {
                throw $openAiError;
            }

            $fallbackProviders = $this->secondaryVideoProvidersForOpenAiFailure(!empty($generationReferenceAbsPool));
            $fallbackFailures = [];

            foreach ($fallbackProviders as $fallbackProvider) {
                try {
                    if ($fallbackProvider === 'runway') {
                        $result = $this->generateVideoWithRunway(
                            runway: $runway,
                            openAi: $openAi,
                            item: $item,
                            briefRaw: $briefRaw,
                            fallbackPrompt: $prompt,
                            videoPrompt: $runwayExecutionPrompt,
                            referenceAbs: $referenceAbs,
                            referencePath: $referencePath,
                            referencePaths: $referencePaths,
                            referenceReason: $referenceReason . '_runway_fallback_after_openai_failure',
                            generationReferenceAbsPool: $generationReferenceAbsPool,
                            imageReferencePathPool: $imageReferencePathPool,
                            validationReferenceAbsPool: $validationReferenceAbsPool,
                            mustEnforceExplicitReferences: $mustEnforceExplicitReferences,
                            compositionMeta: $compositionMeta,
                            brandDecision: $brandDecision,
                            videoOptions: $videoOptions
                        );
                    } else {
                        $result = $this->generateVideoWithKling(
                            kling: $kling,
                            item: $item,
                            briefRaw: $briefRaw,
                            fallbackPrompt: $prompt,
                            videoPrompt: $klingExecutionPrompt,
                            referenceAbsPool: $generationReferenceAbsPool,
                            referencePaths: $imageReferencePathPool,
                            referenceReason: $referenceReason . '_kling_fallback_after_openai_failure',
                            compositionMeta: $compositionMeta,
                            brandDecision: $brandDecision,
                            videoOptions: $videoOptions,
                            assetVariables: $assetVariables,
                            activeFeedbackRequest: $activeFeedbackRequest,
                            locationSequenceMode: $locationSequenceMode
                        );
                    }

                    $result['provider_fallback'] = [
                        'from' => 'openai',
                        'to' => $fallbackProvider,
                        'reason' => Str::limit($openAiError->getMessage(), 220, ''),
                        'at' => now()->toDateTimeString(),
                    ];

                    return $result;
                } catch (Throwable $fallbackError) {
                    $fallbackFailures[] = $fallbackProvider . ': ' . Str::limit($fallbackError->getMessage(), 220, '');

                    Log::warning('GenerateAiForContentItem video fallback failed', [
                        'content_item_id' => $item->id,
                        'from_provider' => 'openai',
                        'to_provider' => $fallbackProvider,
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            if (!empty($fallbackFailures)) {
                throw new \RuntimeException(
                    $openAiError->getMessage() . ' | VIDEO_PROVIDER_FALLBACKS_FAILED=' . implode(' || ', $fallbackFailures),
                    0,
                    $openAiError
                );
            }

            throw $openAiError;
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

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    private function buildStrategicVideoPrompt(
        ContentItem $item,
        array $meta,
        string $briefRaw,
        ?string $selectedBrandImageAbs,
        array $generationReferenceAbsPool,
        array $referencePaths,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $logoRequested,
        ?string $logoAbs,
        string $logoMode,
        bool $locationSequenceMode,
        bool $mustEnforceExplicitReferences
    ): string {
        $storedVideoPrompt = trim((string) data_get($meta, 'video_prompt', ''));
        if ($storedVideoPrompt !== '') {
            $parts = [$storedVideoPrompt];
        } else {
            $brandName = trim((string) data_get($meta, 'tenant_profile.business_name', 'Brand'));
            $industry = trim((string) data_get($meta, 'tenant_profile.industry', ''));
            $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', 'Awareness')));
            $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
            $parts = [
                "Crea un reel video verticale 9:16 per {$brandName}.",
                $industry !== '' ? "Settore: {$industry}." : '',
                $briefRaw !== '' ? "Brief prioritario: {$briefRaw}." : '',
                $objective !== '' ? "Obiettivo: {$objective}." : '',
                $tone !== '' ? "Tono del brand: {$tone}." : '',
            ];
        }

        $parts[] = 'Video social realistico, elegante e vendibile, pensato per Instagram Reel.';
        $parts[] = 'Apri con un primo secondo forte e dinamico, poi accompagna la scena con movimenti fluidi e naturali.';
        $parts[] = 'Niente testo in sovraimpressione, niente watermark, niente loghi inventati, niente look da spot corporate.';
        $parts[] = 'Se compaiono persone, devono sembrare reali, spontanee e coerenti con il contesto.';
        $parts[] = $this->personIdentityVideoInstruction($assetVariables);
        $parts[] = $this->subjectLockVideoInstruction(
            $meta,
            $this->videoSubjectContextText($meta, $briefRaw, (string) data_get($meta, 'video_prompt', '')),
            $assetVariables
        );
        $parts[] = $this->feedbackDrivenVideoInstruction($activeFeedbackRequest, $assetVariables, $locationSequenceMode);

        if ($locationSequenceMode) {
            $locationNames = $this->videoLocationSequenceNames($assetVariables);
            if (!empty($locationNames)) {
                $parts[] = 'Le aree reali da mostrare sono: ' . implode(', ', $locationNames) . '.';
            }
            $parts[] = 'Queste location sono ambienti reali diversi dello stesso locale: mostrali in sequenza come scene separate e riconoscibili.';
            $parts[] = 'Non fonderli in un unica stanza, non inventare nuove sale, non cambiare architettura, prospettiva o layout del posto.';
            $parts[] = 'Usa transizioni naturali tra un ambiente e l altro come in un reel editoriale premium.';
        } elseif (!empty($generationReferenceAbsPool)) {
            if (count($referencePaths) > 1) {
                $parts[] = 'Prendi i riferimenti come base narrativa coerente senza trasformarli in un collage o in una stanza impossibile.';
            } else {
                $parts[] = 'Mantieni il DNA visivo reale del luogo o del soggetto di riferimento, migliorando ritmo e resa video.';
            }
        } elseif ($selectedBrandImageAbs) {
            $parts[] = 'Riprendi il tema del brand in modo creativo senza sembrare una foto statica animata.';
        }

        if ($this->hasProtectedLocationEnvelope($assetVariables, $referencePaths)) {
            $parts[] = 'Il luogo reale deve restare autentico e riconoscibile.';
        }

        if ($logoRequested && $logoAbs) {
            $parts[] = $logoMode === 'background'
                ? 'Se usi il logo reale, tienilo discreto sullo sfondo senza farlo dominare la scena.'
                : 'Se usi il logo reale, integralo in modo naturale e plausibile nella scena.';
        }

        if ($mustEnforceExplicitReferences) {
            $parts[] = 'Usa i riferimenti richiesti dall utente in modo riconoscibile, senza sostituire i soggetti principali con alternative casuali.';
        }

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 900, '');
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>
     */
    private function normalizeReelBlueprint(
        array $blueprint,
        ContentItem $item,
        array $meta,
        array $assetVariables,
        string $videoPrompt
    ): array {
        if (Str::lower(trim((string) ($item->format ?? 'post'))) !== 'reel') {
            return [];
        }

        $fallback = $this->fallbackReelBlueprint($item, $meta, $assetVariables, $videoPrompt);
        if (empty($blueprint)) {
            return $fallback;
        }

        $shots = [];
        foreach (array_slice((array) ($blueprint['shots'] ?? []), 0, 4) as $index => $shot) {
            if (!is_array($shot)) {
                continue;
            }

            $purpose = Str::limit(trim((string) ($shot['purpose'] ?? '')), 120, '');
            $subject = Str::limit(trim((string) ($shot['subject'] ?? '')), 120, '');
            $camera = Str::limit(trim((string) ($shot['camera'] ?? '')), 90, '');
            $motion = Str::limit(trim((string) ($shot['motion'] ?? '')), 90, '');

            if ($purpose === '' && $subject === '' && $camera === '' && $motion === '') {
                continue;
            }

            $shots[] = [
                'order' => max(1, (int) ($shot['order'] ?? ($index + 1))),
                'purpose' => $purpose !== '' ? $purpose : (string) data_get($fallback, "shots.{$index}.purpose", ''),
                'subject' => $subject !== '' ? $subject : (string) data_get($fallback, "shots.{$index}.subject", ''),
                'camera' => $camera !== '' ? $camera : (string) data_get($fallback, "shots.{$index}.camera", ''),
                'motion' => $motion !== '' ? $motion : (string) data_get($fallback, "shots.{$index}.motion", ''),
            ];
        }

        if (count($shots) < 3) {
            $shots = (array) ($fallback['shots'] ?? []);
        }

        return [
            'hook' => Str::limit(trim((string) ($blueprint['hook'] ?? '')), 140, '') ?: (string) ($fallback['hook'] ?? ''),
            'anchor_frame' => Str::limit(trim((string) ($blueprint['anchor_frame'] ?? '')), 160, '') ?: (string) ($fallback['anchor_frame'] ?? ''),
            'continuity_lock' => Str::limit(trim((string) ($blueprint['continuity_lock'] ?? '')), 220, '') ?: (string) ($fallback['continuity_lock'] ?? ''),
            'visual_payoff' => Str::limit(trim((string) ($blueprint['visual_payoff'] ?? '')), 140, '') ?: (string) ($fallback['visual_payoff'] ?? ''),
            'shots' => array_values($shots),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>
     */
    private function fallbackReelBlueprint(
        ContentItem $item,
        array $meta,
        array $assetVariables,
        string $videoPrompt
    ): array {
        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', 'awareness')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $controlContext = $this->videoSubjectContextText($meta, (string) data_get($meta, 'manual_brief', ''), $videoPrompt);
        $needsDualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $controlContext, $assetVariables);
        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $controlContext));
        $productLabel = $productLabel !== '' ? $productLabel : 'il veicolo o prodotto reale richiesto';

        if ($needsDualSubjectLock) {
            $mainSubject = "la persona reale del brand insieme a {$productLabel}";
            $anchor = "hero frame verticale con la persona reale del brand e {$productLabel} chiaramente visibili nello stesso shot";
            $continuity = "mantieni sempre la stessa persona, stessi lineamenti e {$productLabel} con marca, modello e colore coerenti tra gli shot";
        } else {
            $mainSubject = $hasPersonVariable
                ? 'la persona reale del brand, uguale ai riferimenti'
                : 'il soggetto principale del contenuto, riconoscibile da subito';
            $anchor = $hasPersonVariable
                ? 'medium shot verticale della persona reale del brand nel contesto vero, subito riconoscibile'
                : 'hero frame verticale pulito del soggetto principale nel contesto reale del brand';
            $continuity = $hasPersonVariable
                ? 'mantieni sempre la stessa persona, stesso volto, stessi lineamenti e stessa presenza'
                : 'mantieni lo stesso spazio, lo stesso soggetto principale e la stessa palette del brand';
        }

        $payoff = $angle !== ''
            ? "chiusura pulita che fa percepire {$angle}"
            : 'chiusura pulita con payoff visivo coerente con il brand';
        $hook = $objective !== ''
            ? "apertura forte entro il primo secondo per far percepire {$objective}"
            : 'apertura forte entro il primo secondo con soggetto chiaro e leggibile';
        $toneHint = $tone !== '' ? "con tono {$tone}" : 'con tono coerente al brand';

        $shotTwoSubject = $needsDualSubjectLock
            ? "{$productLabel} in evidenza con interazione credibile della persona reale del brand"
            : $mainSubject;
        $shotThreeSubject = $needsDualSubjectLock
            ? "payoff finale con persona reale del brand e {$productLabel} ancora presenti e riconoscibili"
            : $mainSubject;

        return [
            'hook' => $hook,
            'anchor_frame' => $anchor,
            'continuity_lock' => $continuity,
            'visual_payoff' => $payoff,
            'shots' => [
                [
                    'order' => 1,
                    'purpose' => 'hook iniziale immediato',
                    'subject' => $mainSubject,
                    'camera' => 'wide o medium shot verticale leggibile',
                    'motion' => 'push-in leggero o reveal breve',
                ],
                [
                    'order' => 2,
                    'purpose' => $angle !== '' ? "sviluppo dell angolo {$angle}" : 'sviluppo del contesto e del valore del contenuto',
                    'subject' => $shotTwoSubject,
                    'camera' => 'angolazione diversa ma coerente',
                    'motion' => 'tracking morbido o micro parallax',
                ],
                [
                    'order' => 3,
                    'purpose' => $objective !== '' ? "chiusura che spinge {$objective}" : 'payoff finale del reel',
                    'subject' => $shotThreeSubject,
                    'camera' => 'close medium o dettaglio premium',
                    'motion' => 'movimento pulito e conclusivo',
                ],
            ],
            'source_prompt' => Str::limit($videoPrompt, 180, ''),
            'tone_hint' => $toneHint,
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>|null
     */
    private function compactReelBlueprintSummary(array $blueprint): ?array
    {
        if (empty($blueprint)) {
            return null;
        }

        $shots = collect((array) ($blueprint['shots'] ?? []))
            ->map(function ($shot) {
                if (!is_array($shot)) {
                    return null;
                }

                return [
                    'order' => (int) ($shot['order'] ?? 0),
                    'purpose' => Str::limit(trim((string) ($shot['purpose'] ?? '')), 70, ''),
                    'camera' => Str::limit(trim((string) ($shot['camera'] ?? '')), 60, ''),
                    'motion' => Str::limit(trim((string) ($shot['motion'] ?? '')), 60, ''),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'hook' => Str::limit(trim((string) ($blueprint['hook'] ?? '')), 90, ''),
            'anchor_frame' => Str::limit(trim((string) ($blueprint['anchor_frame'] ?? '')), 90, ''),
            'continuity_lock' => Str::limit(trim((string) ($blueprint['continuity_lock'] ?? '')), 120, ''),
            'visual_payoff' => Str::limit(trim((string) ($blueprint['visual_payoff'] ?? '')), 90, ''),
            'shot_count' => count($shots),
            'shots' => $shots,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     */
    private function buildRunwayReelExecutionPrompt(
        string $videoPrompt,
        ContentItem $item,
        array $meta,
        array $assetVariables
    ): string {
        $format = Str::lower(trim((string) ($item->format ?? 'post')));
        if ($format !== 'reel') {
            return $videoPrompt;
        }

        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $series = trim((string) data_get($meta, 'item_brain.series', data_get($meta, 'editorial.series', '')));
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $controlContext = $this->videoSubjectContextText($meta, (string) data_get($meta, 'manual_brief', ''), $videoPrompt);
        $needsDualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $controlContext, $assetVariables);
        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $controlContext));
        $productLabel = $productLabel !== '' ? $productLabel : 'il veicolo o prodotto richiesto nel brief';
        $blueprint = $this->normalizeReelBlueprint(
            blueprint: is_array(data_get($meta, 'reel_blueprint', [])) ? (array) data_get($meta, 'reel_blueprint', []) : [],
            item: $item,
            meta: $meta,
            assetVariables: $assetVariables,
            videoPrompt: $videoPrompt
        );

        $parts = [$videoPrompt];
        $parts[] = 'Runway execution mode: una sola clip coerente, guidata da un anchor frame forte e da una progressione breve.';
        $parts[] = 'Costruisci il contenuto come un vero reel social di 3-5 shot concatenati, non come una clip piatta o ripetitiva.';
        $parts[] = 'Mantieni un hook visivo entro il primo secondo, con soggetto chiaro e subito leggibile.';
        $parts[] = 'Deve sembrare un reel nativo da feed Instagram: stop-scroll, ritmo chiaro, soggetto forte, non una brochure animata.';
        $parts[] = 'Look live-action premium, fotorealistico, con pelle vera, riflessi reali su metallo e carrozzeria, ottiche credibili e movimento naturale.';
        if ($objective !== '') {
            $parts[] = "Obiettivo strategico da far percepire nel reel: {$objective}.";
        }
        if ($tone !== '') {
            $parts[] = "Tono del brand: {$tone}.";
        }
        if ($angle !== '') {
            $parts[] = "Angolo narrativo: {$angle}.";
        }
        if ($series !== '') {
            $parts[] = "Filone editoriale: {$series}.";
        }
        if ($hasPersonVariable) {
            $parts[] = 'Se compare la persona del brand, deve restare la stessa in tutti gli shot del reel.';
        }
        if ($needsDualSubjectLock) {
            $parts[] = "Vincolo visivo: la persona del brand e {$productLabel} devono restare entrambi presenti e riconoscibili.";
            $parts[] = "Non trasformare il reel in un portrait della sola persona: {$productLabel} deve essere ben visibile gia nel hook, nello sviluppo e nel payoff finale.";
            $parts[] = "Se il brief indica marca, modello o colore di {$productLabel}, rispettali senza reinterpretarli.";
        }
        if (!empty($blueprint)) {
            $parts[] = 'Hook: ' . (string) ($blueprint['hook'] ?? '');
            $parts[] = 'Anchor frame: ' . (string) ($blueprint['anchor_frame'] ?? '');
            $parts[] = 'Continuity lock: ' . (string) ($blueprint['continuity_lock'] ?? '');
            foreach ((array) ($blueprint['shots'] ?? []) as $shot) {
                if (!is_array($shot)) {
                    continue;
                }
                $parts[] = sprintf(
                    'Shot %d: %s. Soggetto: %s. Camera: %s. Movimento: %s.',
                    max(1, (int) ($shot['order'] ?? 1)),
                    trim((string) ($shot['purpose'] ?? '')),
                    trim((string) ($shot['subject'] ?? '')),
                    trim((string) ($shot['camera'] ?? '')),
                    trim((string) ($shot['motion'] ?? ''))
                );
            }
            $parts[] = 'Payoff finale: ' . (string) ($blueprint['visual_payoff'] ?? '');
        } else {
            $parts[] = 'Struttura consigliata: hook visivo entro il primo secondo, sviluppo con 2 o 3 scene leggibili, chiusura con payoff visivo pulito.';
        }
        $parts[] = 'Ogni shot deve cambiare davvero per angolazione, distanza, movimento camera o dettaglio principale, mantenendo continuita narrativa.';
        $parts[] = 'Movimenti camera preferiti: push-in leggero, reveal laterale, tracking morbido, micro parallax. Evita motion caotico.';

        $limit = (int) (config('runway.max_prompt_chars') ?: 980);
        $limit = max(300, min(1000, $limit));

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), $limit, '');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $activeFeedbackRequest
     */
    private function buildKlingExecutionPrompt(
        string $videoPrompt,
        ContentItem $item,
        array $meta,
        array $assetVariables,
        array $activeFeedbackRequest
    ): string {
        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $series = trim((string) data_get($meta, 'item_brain.series', data_get($meta, 'editorial.series', '')));
        $isReel = Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel';
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $controlContext = $this->videoSubjectContextText($meta, (string) data_get($meta, 'manual_brief', ''), $videoPrompt);
        $needsDualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $controlContext, $assetVariables);
        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $controlContext));
        $productLabel = $productLabel !== '' ? $productLabel : 'il veicolo o prodotto richiesto nel brief';

        $parts = [$videoPrompt];
        if ($needsDualSubjectLock) {
            $parts[] = 'Kling execution rule: the references describe one coherent scene with two fixed anchors, the brand person and the requested product or vehicle.';
            $parts[] = "Keep both anchors stable across the full video: same face, same age perception, same hair and same body proportions for the person; same model, same color and same proportions for {$productLabel}.";
            $parts[] = "Do not let the person replace {$productLabel} and do not let {$productLabel} disappear after the first shot.";
        } else {
            $parts[] = 'Kling execution rule: if multiple reference images are present, they describe the same real subject from different angles, not different people.';
            $parts[] = 'Keep one single subject identity across the full video: same face, same age perception, same hair, same body proportions, same overall presence.';
        }
        $parts[] = 'Change scene, camera, gesture, styling and lighting only when useful, but do not drift identity.';
        $parts[] = 'No duplicate subject, no face swap, no identity drift, no extra people replacing the brand subject.';
        $parts[] = 'Live-action photorealism only: real skin texture, real lens behavior, realistic reflections on metal and paint, natural motion, no stylized rendering.';

        if ($isReel) {
            $parts[] = 'Build a vertical 9:16 social reel with 3 to 5 clear shots, a fast hook in the first second and a clean visual payoff at the end.';
            $parts[] = 'Treat it like a native Instagram reel: quick readability, clear camera intention, premium pacing, no static brochure feel.';
        }

        if ($objective !== '') {
            $parts[] = "Strategic objective: {$objective}.";
        }
        if ($tone !== '') {
            $parts[] = "Brand tone: {$tone}.";
        }
        if ($angle !== '') {
            $parts[] = "Narrative angle: {$angle}.";
        }
        if ($series !== '') {
            $parts[] = "Editorial series: {$series}.";
        }
        if ($hasPersonVariable) {
            $parts[] = 'Use the persona pack as an identity board first, and only secondarily as style guidance.';
        }
        if ($needsDualSubjectLock) {
            $parts[] = "The person and {$productLabel} must both be visible in the opening hook and return again in the final payoff shot.";
            $parts[] = "If the brief specifies brand, model or color for {$productLabel}, preserve them exactly.";
        }
        if ($this->feedbackTargetsVisual($activeFeedbackRequest)) {
            $parts[] = 'This generation follows a correction request: the new result must visibly improve, not just slightly vary the previous cut.';
        }

        $limit = (int) (config('kling.max_prompt_chars') ?: 1400);
        $limit = max(400, min(1800, $limit));

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), $limit, '');
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @return array<int, string>
     */
    private function videoLocationSequenceNames(array $assetVariables): array
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        $names = [];
        foreach ($resolved as $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
            if ($kind !== 'location') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique(array_slice($names, 0, 4)));
    }

    private function resolveVideoProvider(array $meta): string
    {
        $preferred = trim((string) data_get($meta, 'video_provider', ''));
        if ($preferred !== '') {
            return VideoProviderResolver::normalize($preferred);
        }

        $default = VideoProviderResolver::default();
        if ($this->isVideoProviderConfigured($default)) {
            return $default;
        }

        if ($default !== 'runway' && $this->isVideoProviderConfigured('runway')) {
            return 'runway';
        }

        return VideoProviderResolver::default();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $referencePaths
     */
    private function resolveRunwayVideoModel(ContentItem $item, array $meta, array $assetVariables, array $referencePaths): string
    {
        $explicit = trim((string) data_get($meta, 'video_model', ''));
        if ($explicit !== '') {
            return $this->normalizeRunwayVideoModel($explicit);
        }

        $configured = strtolower(trim((string) (config('runway.model') ?: '')));
        if ($configured === '' || in_array($configured, ['gen4_turbo', 'gen4-turbo'], true)) {
            $configured = 'gen4.5';
        }

        $format = strtolower(trim((string) ($item->format ?? 'post')));
        $hasReferences = !empty(array_filter($referencePaths, fn ($path) => is_string($path) && trim($path) !== ''));
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);

        if ($hasPersonVariable || $hasReferences || $format === 'reel') {
            return $configured;
        }

        return $configured;
    }

    /**
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    private function normalizeVideoOptionsForProvider(string $provider, array $videoOptions): array
    {
        $provider = strtolower(trim($provider));
        $seconds = (int) ($videoOptions['seconds'] ?? 0);
        if ($seconds <= 0) {
            $seconds = match ($provider) {
                'runway' => (int) (config('runway.video_seconds') ?: 8),
                'kling' => (int) (config('kling.video_seconds') ?: 5),
                default => (int) (config('openai.video_seconds') ?: 8),
            };
        }

        $size = trim((string) ($videoOptions['size'] ?? ''));
        if ($size === '') {
            $size = (string) (config('openai.video_size') ?: '720x1280');
        }

        $model = trim((string) ($videoOptions['model'] ?? ''));
        $model = match ($provider) {
            'runway' => $this->normalizeRunwayVideoModel($model),
            'kling' => $this->normalizeKlingVideoModel($model),
            default => $this->normalizeOpenAiVideoModel($model),
        };

        $videoOptions['model'] = $model;
        $videoOptions['seconds'] = $seconds;
        $videoOptions['size'] = $size;

        return $videoOptions;
    }

    private function normalizeOpenAiVideoModel(string $model): string
    {
        $model = strtolower(trim($model));
        if ($model !== '' && str_starts_with($model, 'sora-2')) {
            return $model;
        }

        $configured = strtolower(trim((string) (config('openai.video_model') ?: 'sora-2')));

        return str_starts_with($configured, 'sora-2') ? $configured : 'sora-2';
    }

    private function normalizeRunwayVideoModel(string $model): string
    {
        $model = strtolower(trim($model));
        if ($model !== '' && !str_starts_with($model, 'sora-') && !str_starts_with($model, 'kling-')) {
            return in_array($model, ['gen4_turbo', 'gen4-turbo'], true) ? 'gen4.5' : $model;
        }

        $configured = strtolower(trim((string) (config('runway.model') ?: 'gen4.5')));
        if ($configured === '' || str_starts_with($configured, 'sora-') || str_starts_with($configured, 'kling-')) {
            return 'gen4.5';
        }

        return in_array($configured, ['gen4_turbo', 'gen4-turbo'], true) ? 'gen4.5' : $configured;
    }

    private function normalizeKlingVideoModel(string $model): string
    {
        $model = strtolower(trim($model));
        if ($model !== '' && str_starts_with($model, 'kling-')) {
            return $model;
        }

        $configured = strtolower(trim((string) (config('kling.model') ?: '')));

        return str_starts_with($configured, 'kling-') ? $configured : '';
    }

    private function resolveImageProvider(array $meta): string
    {
        $source = trim((string) data_get($meta, 'source', ''));
        $mode = trim((string) data_get($meta, 'plan.mode', ''));

        if (!in_array($source, ['manual_single_content'], true) && $mode !== 'single_manual') {
            return ImageProviderResolver::default();
        }

        return ImageProviderResolver::resolve((string) data_get($meta, 'image_provider', ''), ImageProviderResolver::default());
    }

    private function generateImageTextWithProvider(
        string $provider,
        string $prompt,
        OpenAiService $openAi,
        NanoBananaService $nanoBanana
    ): array {
        if ($provider === 'openai') {
            return $openAi->generateImageBase64($prompt);
        }

        return $nanoBanana->generateImageBase64($prompt);
    }

    /**
     * @param  array<int, string>  $editPaths
     */
    private function generateImageEditWithProvider(
        string $provider,
        string $prompt,
        array $editPaths,
        OpenAiService $openAi,
        NanoBananaService $nanoBanana
    ): array {
        if ($provider === 'openai') {
            return $openAi->generateImageEditBase64($prompt, $editPaths);
        }

        return $nanoBanana->generateImageEditBase64($prompt, $editPaths);
    }

    /**
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    private function augmentPromptForInstagramImageExecution(
        ContentItem $item,
        string $prompt,
        array $selectedBrandImagePaths,
        array $assetVariables,
        array $activeFeedbackRequest = []
    ): string {
        $itemBrain = is_array(data_get($item->ai_meta, 'item_brain', []))
            ? data_get($item->ai_meta, 'item_brain', [])
            : [];
        $forcePhotorealism = ImagePromptRealismGuard::shouldForcePhotorealism(
            (string) data_get($item->ai_meta, 'manual_brief', ''),
            $prompt
        );
        $locationEnvelopeProtected = $this->hasProtectedLocationEnvelope($assetVariables, $selectedBrandImagePaths);
        $hasExplicitHumanReferences = $this->hasExplicitHumanReferences($assetVariables);
        $prompt = ImagePromptRealismGuard::sanitize($prompt, $forcePhotorealism);

        $parts = [
            trim($prompt),
            $this->instagramVisualOutputInstruction($item),
            $this->socialGraphicSystemInstruction($item, $itemBrain),
            $this->multiReferenceBlendInstruction($selectedBrandImagePaths),
            $this->locationEnvelopePreservationInstruction($assetVariables, $selectedBrandImagePaths),
            $this->feedbackDrivenImageInstruction($activeFeedbackRequest, $selectedBrandImagePaths, $assetVariables),
            ImagePromptRealismGuard::instruction($forcePhotorealism, $locationEnvelopeProtected, $hasExplicitHumanReferences),
            'Il risultato deve sembrare un contenuto editoriale premium pensato per Instagram, non una demo tecnica.',
        ];

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    private function instagramVisualOutputInstruction(ContentItem $item): string
    {
        return 'Output finale verticale 4:5, pronto per Instagram feed, con composizione premium, focus principale netto, margini puliti e gerarchia visiva forte. Questo deve sembrare un post social studiato per fermare lo scroll, non una foto corporate generica: soggetto chiaro subito, lettura forte anche da miniatura e resa nativa da feed.';
    }

    /**
     * @param  array<string, mixed>  $itemBrain
     */
    private function socialGraphicSystemInstruction(ContentItem $item, array $itemBrain): string
    {
        $position = $this->positionInPlan($item) + 1;
        $total = max(1, $this->totalItemsInPlan($item));
        $seriesName = trim((string) data_get($itemBrain, 'series_name', ''));
        $connectionHint = trim((string) data_get($itemBrain, 'connection_hint', ''));

        $parts = [
            "Questo contenuto fa parte di un piano di pubblicazioni social: posizione {$position} di {$total}.",
            'Il visual deve funzionare come post social vero: stop-scroll, impatto immediato, gerarchia visiva chiara, niente look da brochure o catalogo statico.',
            'Mantieni coerenza con il feed del brand, ma rendi il contenuto visivamente distinto dai post vicini con un angolo e una composizione pensati per i social.',
        ];

        if ($seriesName !== '') {
            $parts[] = "Serie o filone: {$seriesName}.";
        }

        if ($connectionHint !== '') {
            $parts[] = "Ruolo nel piano: {$connectionHint}";
        }

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    private function multiReferenceBlendInstruction(array $selectedBrandImagePaths): string
    {
        $paths = array_values(array_filter($selectedBrandImagePaths, fn ($path) => is_string($path) && $path !== ''));

        if (count($paths) < 2) {
            return 'Se usi un solo riferimento, mantieni il DNA visivo reale ma migliora resa, inquadratura e impatto social.';
        }

        return 'Se usi piu immagini di riferimento, fondile in un unica scena coerente e credibile: niente collage, niente split-screen, niente griglia, niente foto appoggiate una sopra l altra. Unifica luce, prospettiva, palette, styling e contesto narrativo come se fosse un solo shooting strategico.';
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<int, string>  $selectedBrandImagePaths
     * @param  array<string, mixed>  $assetVariables
     * @return array<int, string>
     */
    private function stabilizeReferencePathsForFeedback(
        array $selectedBrandImagePaths,
        array $feedbackRequest,
        array $assetVariables
    ): array {
        $paths = array_values(array_filter($selectedBrandImagePaths, fn ($path) => is_string($path) && trim($path) !== ''));

        if (count($paths) < 2) {
            return $paths;
        }

        if (!$this->feedbackForcesPrimaryLocationAnchor($feedbackRequest, $assetVariables, $paths)) {
            return $paths;
        }

        return [$paths[0]];
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<int, string>  $selectedBrandImagePaths
     * @param  array<string, mixed>  $assetVariables
     */
    private function feedbackDrivenImageInstruction(
        array $feedbackRequest,
        array $selectedBrandImagePaths,
        array $assetVariables
    ): string {
        if (!$this->feedbackTargetsVisual($feedbackRequest)) {
            return '';
        }

        $category = Str::lower(trim((string) ($feedbackRequest['category'] ?? '')));
        $reason = trim((string) ($feedbackRequest['reason'] ?? ''));
        $parts = [];

        if ($reason !== '') {
            $parts[] = 'Correzione prioritaria utente da rispettare davvero: ' . $reason . '.';
        }

        if ($this->feedbackForcesPrimaryLocationAnchor($feedbackRequest, $assetVariables, $selectedBrandImagePaths)) {
            $parts[] = 'Usa la prima immagine reale come ancora strutturale obbligatoria del luogo.';
            $parts[] = 'Non inventare nuove sale, muri, finestre, aperture, prospettive o layout diversi.';
            $parts[] = 'Se esistono altri riferimenti, servono solo per dettagli secondari coerenti, non per fondere ambienti diversi.';
        }

        if ($category === 'realism') {
            $parts[] = 'Se aggiungi persone, evita close-up inventati e preferisci figure credibili in media distanza, con volti, mani e postura naturali.';
        }

        if ($category === 'visual_composition') {
            $parts[] = 'Cambia davvero composizione, inquadratura e gerarchia visiva, mantenendo pero il luogo autentico se e reale.';
        }

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<string, mixed>  $assetVariables
     */
    private function feedbackDrivenVideoInstruction(
        array $feedbackRequest,
        array $assetVariables,
        bool $locationSequenceMode = false
    ): string {
        if (!$this->feedbackTargetsVisual($feedbackRequest)) {
            return '';
        }

        $category = Str::lower(trim((string) ($feedbackRequest['category'] ?? '')));
        $reason = trim((string) ($feedbackRequest['reason'] ?? ''));
        $reasonNormalized = $this->normalizeText($reason);
        $parts = [];

        if ($reason !== '') {
            $parts[] = 'Correzione video prioritaria da rispettare davvero: ' . $reason . '.';
        }

        $parts[] = 'Questa e una rigenerazione dopo feedback negativo: il nuovo video deve cambiare in modo evidente rispetto alla versione precedente, non basta una micro-variazione.';
        $parts[] = 'Cambia davvero apertura, sequenza delle scene, regia, camera movement, ritmo o styling mantenendo obiettivo e strategia.';

        if ($this->hasPersonAssetVariable($assetVariables)) {
            $parts[] = 'Se c e una persona di riferimento del brand, la sua identita deve restare la stessa tra una versione e l altra: stesso volto, stessi lineamenti, stessa eta apparente e stessa presenza.';
        }

        if ($this->feedbackDemandsPersonaIdentityLock($reasonNormalized)) {
            $parts[] = 'La persona deve sembrare davvero quella dei riferimenti: usa volto, lineamenti, proporzioni, capelli e presenza come ancora rigida.';
        }

        if ($locationSequenceMode || $category === 'location_integrity') {
            $parts[] = 'Mantieni i luoghi reali autentici e separati se sono ambienti diversi, senza fonderli o inventare spazi nuovi.';
        }

        if ($category === 'realism') {
            $parts[] = 'Volti, mani, postura, sguardo e movimenti devono risultare naturali e credibili, senza uncanny effect.';
        }

        if ($category === 'visual_composition') {
            $parts[] = 'Cambia in modo netto lo shot plan: inquadratura iniziale, ordine dei frame, distanze camera e gerarchia visiva delle scene.';
        }

        if ($category === 'brand_alignment') {
            $parts[] = 'Riallinea outfit, atmosfera, ambiente, luce e comportamento del soggetto al posizionamento reale del brand.';
        }

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    private function feedbackForcesPrimaryLocationAnchor(
        array $feedbackRequest,
        array $assetVariables,
        array $selectedBrandImagePaths
    ): bool {
        $category = Str::lower(trim((string) ($feedbackRequest['category'] ?? '')));
        $reason = $this->normalizeText((string) ($feedbackRequest['reason'] ?? ''));

        if ($category === 'location_integrity') {
            return true;
        }

        if (!$this->hasProtectedLocationEnvelope($assetVariables, $selectedBrandImagePaths)) {
            return false;
        }

        foreach ([
            'sala inventata',
            'altra sala',
            'ambiente diverso',
            'non cambiare il locale',
            'deve rimanere com e',
            'deve restare com e',
            'non inventare',
        ] as $needle) {
            if ($reason !== '' && str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function feedbackDemandsPersonaIdentityLock(string $reasonNormalized): bool
    {
        if ($reasonNormalized === '') {
            return false;
        }

        foreach ([
            'non sembra lei',
            'non e lei',
            'non ÃƒÂ¨ lei',
            'volto diverso',
            'viso diverso',
            'faccia diversa',
            'non sembra la persona',
            'non riconoscibile',
        ] as $needle) {
            if (str_contains($reasonNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     */
    private function feedbackTargetsVisual(array $feedbackRequest): bool
    {
        if (empty($feedbackRequest)) {
            return false;
        }

        $scope = Str::lower(trim((string) ($feedbackRequest['scope'] ?? '')));
        $category = Str::lower(trim((string) ($feedbackRequest['category'] ?? '')));

        if (in_array($scope, ['visual_first', 'full'], true)) {
            return true;
        }

        return in_array($category, ['realism', 'visual_composition', 'location_integrity'], true);
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @return array<string, mixed>
     */
    private function normalizeFeedbackRequest(array $feedbackRequest): array
    {
        if (empty($feedbackRequest)) {
            return [];
        }

        return [
            'feedback_id' => isset($feedbackRequest['feedback_id']) ? (int) $feedbackRequest['feedback_id'] : null,
            'sentiment' => trim((string) ($feedbackRequest['sentiment'] ?? '')),
            'category' => Str::lower(trim((string) ($feedbackRequest['category'] ?? ''))),
            'scope' => Str::lower(trim((string) ($feedbackRequest['scope'] ?? 'full'))),
            'reason' => trim((string) ($feedbackRequest['reason'] ?? '')),
            'action' => trim((string) ($feedbackRequest['action'] ?? '')),
            'instruction' => trim((string) ($feedbackRequest['instruction'] ?? '')),
            'created_at' => trim((string) ($feedbackRequest['created_at'] ?? '')),
            'requested_at' => trim((string) ($feedbackRequest['requested_at'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    private function locationEnvelopePreservationInstruction(array $assetVariables, array $selectedBrandImagePaths): string
    {
        if (!$this->hasProtectedLocationEnvelope($assetVariables, $selectedBrandImagePaths)) {
            return '';
        }

        return 'Se tra i riferimenti c e un luogo reale come ufficio, edificio, showroom o punto vendita, quello resta l involucro principale da preservare: mantieni architettura, layout, prospettiva e dettagli distintivi del posto. Puoi aggiungere persone, prodotti, allestimenti, decorazioni e atmosfera coerenti con il brief, ma non trasformare quel luogo in un ambiente diverso.';
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    private function hasProtectedLocationEnvelope(array $assetVariables, array $selectedBrandImagePaths): bool
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        if (empty($resolved)) {
            return false;
        }

        $selectedLookup = array_fill_keys(
            array_values(array_filter($selectedBrandImagePaths, fn ($path) => is_string($path) && $path !== '')),
            true
        );
        $matchAnyLocation = empty($selectedLookup);

        foreach ($resolved as $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
            $text = Str::lower(trim((string) ($row['name'] ?? '') . ' ' . (string) ($row['description'] ?? '')));
            $matchesLocation = $kind === 'location'
                || str_contains($text, 'ufficio')
                || str_contains($text, 'edificio')
                || str_contains($text, 'showroom')
                || str_contains($text, 'negozio')
                || str_contains($text, 'locale')
                || str_contains($text, 'ristorante');

            if (!$matchesLocation) {
                continue;
            }

            $paths = array_values(array_filter(
                (array) ($row['asset_paths'] ?? []),
                fn ($path) => is_string($path) && trim($path) !== ''
            ));

            if ($matchAnyLocation || empty($paths)) {
                return true;
            }

            foreach ($paths as $path) {
                if (isset($selectedLookup[$path])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    private function hasExplicitHumanReferences(array $assetVariables): bool
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        if (empty($resolved)) {
            return false;
        }

        foreach ($resolved as $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
            $text = Str::lower(trim((string) ($row['name'] ?? '') . ' ' . (string) ($row['description'] ?? '')));

            if ($kind === 'person') {
                return true;
            }

            if (
                str_contains($text, 'persona')
                || str_contains($text, 'staff')
                || str_contains($text, 'team')
                || str_contains($text, 'chef')
                || str_contains($text, 'volto')
                || str_contains($text, 'dipendente')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    private function generateVideoWithKling(
        KlingService $kling,
        ContentItem $item,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        array $referenceAbsPool,
        array $referencePaths,
        string $referenceReason,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $locationSequenceMode
    ): array {
        $referenceBundle = $this->buildKlingReferenceInputs($referenceAbsPool, $referencePaths);
        $referenceInputs = (array) ($referenceBundle['inputs'] ?? []);
        $requestMode = $kling->resolveRequestMode($referenceInputs);

        if ($locationSequenceMode && count($referenceInputs) > 1) {
            $referenceInputs = array_values(array_slice($referenceInputs, 0, 1));
            $requestMode = $kling->resolveRequestMode($referenceInputs, 'image');
            $referenceReason .= '_primary_location_anchor_only';
        }

        $requestSummary = (array) ($referenceBundle['summary'] ?? []);
        $requestSummary['identity_board_applied'] = $this->shouldUsePersonIdentityReferenceBoard($assetVariables, $referencePaths);
        $requestSummary['location_sequence_mode'] = $locationSequenceMode;
        $requestSummary['reference_count'] = count($referenceInputs);
        $requestSummary['reference_paths'] = array_values(array_slice($referencePaths, 0, 4));

        $videoOptions = $this->normalizeVideoOptionsForProvider('kling', $videoOptions);
        $klingOptions = [
            'request_mode' => $requestMode,
            'model' => (string) ($videoOptions['model'] ?? ''),
            'mode' => (string) (config('kling.mode') ?: 'pro'),
            'seconds' => (int) ($videoOptions['seconds'] ?? (int) (config('kling.video_seconds') ?: 5)),
            'size' => (string) ($videoOptions['size'] ?? '720x1280'),
            'negative_prompt' => $this->buildKlingNegativePrompt(
                $item,
                $assetVariables,
                $activeFeedbackRequest,
                $locationSequenceMode
            ),
            'external_task_id' => 'socialai-item-' . $item->id . '-' . now()->timestamp,
        ];

        $job = $kling->createVideoJob($videoPrompt, $referenceInputs, $klingOptions);
        $taskId = (string) ($job['id'] ?? '');
        $jobFinal = $kling->waitForVideoCompletion($taskId, (string) ($job['request_mode'] ?? $requestMode));
        $videoBytes = $kling->downloadVideoContent($jobFinal);
        $thumbBytes = $kling->downloadThumbnailContent($jobFinal);

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
            'source' => 'kling_video_generation',
            'provider' => 'kling',
            'video_id' => $taskId,
            'video_path' => $videoPath,
            'thumbnail_path' => $thumbPath,
            'reference_path' => $referencePaths[0] ?? '',
            'reference_paths' => array_values(array_slice($referencePaths, 0, 4)),
            'reference_reason' => $referenceReason,
            'reference_validation' => null,
            'composition_reference' => $compositionMeta,
            'generation_attempts' => 1,
            'job_status' => (string) (
                data_get($jobFinal, 'data.task_status')
                ?? data_get($jobFinal, 'task_status')
                ?? 'succeed'
            ),
            'brand_selection' => $brandDecision,
            'provider_fallback' => null,
            'request_summary' => $job['request_summary'] ?? null,
            'reference_input_summary' => $requestSummary,
        ];
    }

    /**
     * @param  array<int, string>  $referenceAbsPool
     * @param  array<int, string>  $referencePaths
     * @return array{inputs: array<int, string>, summary: array<string, mixed>}
     */
    private function buildKlingReferenceInputs(array $referenceAbsPool, array $referencePaths): array
    {
        $referenceAbsPool = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $referenceAbsPool),
            fn ($value) => $value !== ''
        ));
        $referencePaths = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $referencePaths),
            fn ($value) => $value !== ''
        ));

        $inputs = [];
        $inputModes = [];
        $pairedPaths = [];
        $limit = max(1, min(4, (int) (config('kling.max_reference_images') ?: 4)));

        foreach (array_values(array_slice($referencePaths, 0, $limit)) as $index => $path) {
            $absolutePath = $referenceAbsPool[$index] ?? '';
            $input = $this->buildKlingReferenceInput($path, $absolutePath);
            if ($input === null) {
                continue;
            }

            $inputs[] = $input['value'];
            $inputModes[] = $input['mode'];
            $pairedPaths[] = $path;
        }

        if (empty($inputs)) {
            foreach (array_values(array_slice($referenceAbsPool, 0, $limit)) as $absolutePath) {
                $dataUri = $this->buildKlingDataUri($absolutePath);
                if ($dataUri === null) {
                    continue;
                }

                $inputs[] = $dataUri;
                $inputModes[] = 'data_uri';
            }
        }

        return [
            'inputs' => $inputs,
            'summary' => [
                'input_modes' => array_values(array_unique($inputModes)),
                'paired_reference_paths' => $pairedPaths,
            ],
        ];
    }

    /**
     * @return array{mode:string,value:string}|null
     */
    private function buildKlingReferenceInput(string $storagePath, string $absolutePath): ?array
    {
        if ($this->shouldPreferInlineKlingReference()) {
            $dataUri = $this->buildKlingDataUri($absolutePath);
            if ($dataUri !== null) {
                return [
                    'mode' => 'data_uri',
                    'value' => $dataUri,
                ];
            }
        }

        try {
            return [
                'mode' => 'public_url',
                'value' => PublicMediaUrl::build($storagePath),
            ];
        } catch (Throwable) {
            $dataUri = $this->buildKlingDataUri($absolutePath);
            if ($dataUri === null) {
                return null;
            }

            return [
                'mode' => 'data_uri',
                'value' => $dataUri,
            ];
        }
    }

    private function shouldPreferInlineKlingReference(): bool
    {
        $baseUrl = strtolower(trim((string) (config('kling.base_url') ?: '')));

        return str_contains($baseUrl, 'klingai.com');
    }

    private function buildKlingDataUri(string $absolutePath): ?string
    {
        $absolutePath = trim($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        return base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $activeFeedbackRequest
     */
    private function buildKlingNegativePrompt(
        ContentItem $item,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $locationSequenceMode
    ): string {
        $parts = [
            'identity drift',
            'different face',
            'different age',
            'duplicate subject',
            'extra people replacing the main subject',
            'uncanny eyes',
            'deformed hands',
            'bad anatomy',
            'plastic skin',
            'cartoon look',
            'cgi render',
            '3d animation',
            'anime style',
            'illustration style',
            'beauty filter face',
            'doll face',
            'toy car look',
            'video game cinematic look',
            'text overlay',
            'watermark',
            'fake logo',
        ];

        if ($this->hasPersonAssetVariable($assetVariables)) {
            $parts[] = 'different hairstyle';
            $parts[] = 'different facial structure';
            $parts[] = 'subject inconsistency between shots';
        }

        if ($locationSequenceMode) {
            $parts[] = 'merged rooms';
            $parts[] = 'changed architecture';
            $parts[] = 'invented interiors';
        }

        if ($this->feedbackTargetsVisual($activeFeedbackRequest)) {
            $parts[] = 'too similar to previous version';
        }

        if (Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel') {
            $parts[] = 'flat pacing';
            $parts[] = 'static brochure style';
        }

        return Str::limit(implode(', ', array_values(array_unique($parts))), 600, '');
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    private function generateVideoWithRunway(
        RunwayService $runway,
        OpenAiService $openAi,
        ContentItem $item,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referencePaths,
        string $referenceReason,
        array $generationReferenceAbsPool,
        array $imageReferencePathPool,
        array $validationReferenceAbsPool,
        bool $mustEnforceExplicitReferences,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions
    ): array {
        $videoOptions = $this->normalizeVideoOptionsForProvider('runway', $videoOptions);
        $runwayOptions = [
            'model' => (string) ($videoOptions['model'] ?? config('runway.model') ?: 'gen4.5'),
            'seconds' => (string) ($videoOptions['seconds'] ?? (string) (config('runway.video_seconds') ?: 8)),
            'size' => (string) ($videoOptions['size'] ?? config('openai.video_size') ?: '720x1280'),
        ];

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
                try {
                    $job = $runway->createVideoJob($attemptPrompt, $attemptReferenceAbs, $runwayOptions);
                } catch (Throwable $videoCreateError) {
                    if ($mustEnforceExplicitReferences && !empty($generationReferenceAbsPool)) {
                        $fallbackAbs = $generationReferenceAbsPool[0];
                        $fallbackPrepared = $this->prepareVideoReferenceForSize($fallbackAbs, (string) $runwayOptions['size']);
                        if ($fallbackPrepared) {
                            $attemptTempPreparedPath = $fallbackPrepared;
                            $attemptReferenceAbs = $fallbackPrepared;
                            $attemptReferenceReason = 'runway_retry_with_primary_reference_after_error_normalized';
                        } else {
                            $attemptReferenceAbs = $fallbackAbs;
                            $attemptReferenceReason = 'runway_retry_with_primary_reference_after_error';
                        }

                        $attemptReferencePath = $imageReferencePathPool[0] ?? $attemptReferencePath;
                        $attemptReferencePaths = array_values(array_filter(
                            [$attemptReferencePath],
                            fn ($v) => is_string($v) && $v !== ''
                        ));
                        $job = $runway->createVideoJob($attemptPrompt, $attemptReferenceAbs, $runwayOptions);
                    } else {
                        $attemptReferenceAbs = null;
                        $attemptReferencePath = null;
                        $attemptReferencePaths = [];
                        $attemptReferenceReason = 'runway_retry_without_reference_after_error';
                        $job = $runway->createVideoJob($attemptPrompt, null, $runwayOptions);
                    }
                }

                $videoId = (string) ($job['id'] ?? '');
                $jobFinal = $runway->waitForVideoCompletion($videoId);
                $videoBytes = $runway->downloadVideoContent($jobFinal);
                $thumbBytes = $runway->downloadThumbnailContent($jobFinal);

                $validation = null;
                if ($mustEnforceExplicitReferences && is_string($thumbBytes) && $thumbBytes !== '') {
                    $tmpDir = storage_path('app/tmp');
                    if (!is_dir($tmpDir)) {
                        @mkdir($tmpDir, 0775, true);
                    }

                    $tmpThumbPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-validate-runway-' . Str::uuid()->toString() . '.' . $this->detectImageExtensionFromBytes($thumbBytes);
                    @file_put_contents($tmpThumbPath, $thumbBytes);
                    if (is_file($tmpThumbPath)) {
                        $validation = $openAi->validateVideoFrameWithReferences(
                            brief: $briefRaw !== '' ? $briefRaw : $fallbackPrompt,
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

                $reelBlueprintSummary = $this->compactReelBlueprintSummary(
                    is_array(data_get($item->ai_meta, 'reel_blueprint', [])) ? (array) data_get($item->ai_meta, 'reel_blueprint', []) : []
                );

                return [
                    'source' => 'runway_video_generation',
                    'provider' => 'runway',
                    'video_id' => $videoId,
                    'video_path' => $videoPath,
                    'thumbnail_path' => $thumbPath,
                    'reference_path' => $attemptReferencePath,
                    'reference_paths' => array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== '')),
                    'reference_reason' => $attemptReferenceReason,
                    'reference_validation' => $validation ?? $lastValidation,
                    'composition_reference' => $compositionMeta,
                    'generation_attempts' => $attempt + 1,
                    'job_status' => (string) ($jobFinal['status'] ?? data_get($jobFinal, 'task.status', 'completed')),
                    'brand_selection' => $brandDecision,
                    'request_summary' => [
                        'mode' => 'image_to_video',
                        'model' => (string) ($runwayOptions['model'] ?? ''),
                        'seconds' => (string) ($runwayOptions['seconds'] ?? ''),
                        'size' => (string) ($runwayOptions['size'] ?? ''),
                        'has_prompt_image' => is_string($attemptReferenceAbs) && $attemptReferenceAbs !== '',
                        'reel_blueprint' => $reelBlueprintSummary,
                    ],
                    'reference_input_summary' => [
                        'requested_reference_count' => count($imageReferencePathPool),
                        'active_reference_count' => count(array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== ''))),
                        'validation_reference_count' => count($validationReferenceAbsPool),
                        'reference_reason' => $attemptReferenceReason,
                    ],
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

        throw new \RuntimeException('Runway video generation failed without explicit error.');
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>|null  $providerFallback
     * @return array<string, mixed>
     */
    private function generateVideoWithOpenAi(
        OpenAiService $openAi,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referencePaths,
        string $referenceReason,
        array $generationReferenceAbsPool,
        array $imageReferencePathPool,
        array $validationReferenceAbsPool,
        bool $mustEnforceExplicitReferences,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions,
        array $assetVariables,
        ?array $providerFallback = null
    ): array {
        $videoOptions = $this->normalizeVideoOptionsForProvider('openai', $videoOptions);
        $validationAttempts = $mustEnforceExplicitReferences ? 2 : 1;
        $lastValidation = null;
        $lastError = null;
        $promptVariants = [$videoPrompt];
        $moderationRetryPrompt = $this->buildOpenAiVideoModerationRetryPrompt(
            $videoPrompt,
            $briefRaw !== '' ? $briefRaw : $fallbackPrompt,
            $referencePaths,
            $assetVariables
        );
        if ($moderationRetryPrompt !== '' && $moderationRetryPrompt !== $videoPrompt) {
            $promptVariants[] = $moderationRetryPrompt;
        }

        foreach ($promptVariants as $promptIndex => $basePrompt) {
            for ($attempt = 0; $attempt < $validationAttempts; $attempt++) {
                $attemptPrompt = $basePrompt;
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
                                brief: $briefRaw !== '' ? $briefRaw : $fallbackPrompt,
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
                            if (!$allPresent && ($attempt + 1) < $validationAttempts) {
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
                        'provider' => 'openai',
                        'video_id' => $videoId,
                        'video_path' => $videoPath,
                        'thumbnail_path' => $thumbPath,
                        'reference_path' => $attemptReferencePath,
                        'reference_paths' => array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== '')),
                        'reference_reason' => $attemptReferenceReason,
                        'reference_validation' => $validation ?? $lastValidation,
                        'composition_reference' => $compositionMeta,
                        'generation_attempts' => $attempt + 1 + ($promptIndex * $validationAttempts),
                        'job_status' => (string) ($jobFinal['status'] ?? 'completed'),
                        'brand_selection' => $brandDecision,
                        'provider_fallback' => $providerFallback,
                    ];
                } catch (Throwable $attemptError) {
                    $lastError = $attemptError;
                    if ($this->isOpenAiVideoModerationBlock($attemptError)) {
                        if (($promptIndex + 1) < count($promptVariants)) {
                            break;
                        }
                        throw $attemptError;
                    }

                    if (($attempt + 1) >= $validationAttempts) {
                        throw $attemptError;
                    }
                } finally {
                    if (is_string($attemptTempPreparedPath) && $attemptTempPreparedPath !== '' && is_file($attemptTempPreparedPath)) {
                        @unlink($attemptTempPreparedPath);
                    }
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new \RuntimeException('Video generation failed without explicit error.');
    }

    private function shouldFallbackFromRunwayToOpenAi(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));

        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'missing runway_api_key')) {
            return false;
        }

        if (str_contains($message, 'runway video create error (400)')) {
            return false;
        }

        return str_contains($message, 'runway video generation failed')
            || str_contains($message, 'runway completed payload missing downloadable video url')
            || str_contains($message, 'runway asset download error')
            || str_contains($message, 'runway video generation timeout')
            || str_contains($message, 'curl error')
            || str_contains($message, 'failed to connect');
    }

    private function shouldFallbackFromOpenAiToSecondaryProvider(Throwable $error): bool
    {
        if ($this->isOpenAiVideoModerationBlock($error)) {
            return false;
        }

        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'inpaint image must match')
            || str_contains($message, 'inpaint')
            || str_contains($message, 'input_reference')) {
            return false;
        }

        return str_contains($message, 'openai video create error (500)')
            || str_contains($message, 'openai video retrieve error (500)')
            || str_contains($message, 'openai video create error (502)')
            || str_contains($message, 'openai video retrieve error (502)')
            || str_contains($message, 'openai video create error (503)')
            || str_contains($message, 'openai video retrieve error (503)')
            || str_contains($message, 'openai video create error (504)')
            || str_contains($message, 'openai video retrieve error (504)')
            || str_contains($message, 'server_error')
            || str_contains($message, 'server error')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'gateway timeout')
            || $this->isTransientNetworkError($error);
    }

    /**
     * @return array<int, string>
     */
    private function secondaryVideoProvidersForOpenAiFailure(bool $hasReferencePool): array
    {
        $providers = [];

        if ($this->isVideoProviderConfigured('runway')) {
            $providers[] = 'runway';
        }

        if ($this->isVideoProviderConfigured('kling')) {
            $providers[] = 'kling';
        }

        return $providers;
    }

    private function isVideoProviderConfigured(string $provider): bool
    {
        return match (strtolower(trim($provider))) {
            'openai' => trim((string) (config('openai.api_key') ?: env('OPENAI_API_KEY') ?: '')) !== '',
            'runway' => trim((string) (config('runway.api_key') ?: env('RUNWAY_API_KEY') ?: '')) !== '',
            'kling' => trim((string) (config('kling.access_key') ?: env('KLING_ACCESS_KEY') ?: '')) !== ''
                && trim((string) (config('kling.secret_key') ?: env('KLING_SECRET_KEY') ?: '')) !== '',
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $referencePaths
     */
    private function buildOpenAiVideoFallbackPrompt(string $videoPrompt, string $briefRaw, array $referencePaths): string
    {
        $prompt = trim($videoPrompt);
        $referenceCount = count(array_filter($referencePaths, fn ($path) => is_string($path) && $path !== ''));

        $prompt .= ' Fallback di sicurezza: privilegia un output stabile e pubblicabile.';
        if ($referenceCount > 1) {
            $prompt .= ' Se ci sono piu ambienti reali, mostrali in sequenza con transizioni naturali invece di fonderli in un unico spazio impossibile.';
        }
        if (trim($briefRaw) !== '') {
            $prompt .= ' Brief utente prioritario: ' . trim($briefRaw) . '.';
        }

        return $prompt;
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    private function buildOpenAiVideoModerationRetryPrompt(
        string $videoPrompt,
        string $briefRaw,
        array $referencePaths,
        array $assetVariables
    ): string {
        $safePrompt = $this->buildSafeCommercialVideoPrompt($videoPrompt, $briefRaw, $referencePaths, $assetVariables);
        if ($safePrompt === '') {
            return '';
        }

        return $safePrompt . ' Retry di sicurezza: mantieni il contenuto pulito, professionale e rispettoso.';
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    private function prepareOpenAiVideoPromptForExecution(
        string $videoPrompt,
        string $briefRaw,
        array $referencePaths,
        array $assetVariables
    ): string {
        if (!$this->shouldUseOpenAiVideoModerationGuard($videoPrompt, $briefRaw, $assetVariables)) {
            return $videoPrompt;
        }

        $safePrompt = $this->buildSafeCommercialVideoPrompt($videoPrompt, $briefRaw, $referencePaths, $assetVariables);

        return $safePrompt !== '' ? $safePrompt : $videoPrompt;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    private function personIdentityVideoInstruction(array $assetVariables): string
    {
        $row = $this->singleResolvedPersonVariable($assetVariables);
        if ($row === null) {
            return '';
        }

        $name = trim((string) ($row['name'] ?? ''));
        $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
        $role = trim((string) ($profile['role'] ?? ''));
        $immutable = trim((string) ($profile['immutable_traits'] ?? ''));
        $lookNotes = trim((string) ($profile['look_notes'] ?? ''));
        $stylingNotes = trim((string) ($profile['styling_notes'] ?? ''));
        $promptNotes = trim((string) ($profile['prompt_notes'] ?? ''));

        $parts = [];
        $parts[] = $name !== '' ? "Persona di riferimento del brand: {$name}." : 'Persona di riferimento del brand presente nei riferimenti.';
        if ($role !== '') {
            $parts[] = "Ruolo reale: {$role}.";
        }
        if ($immutable !== '') {
            $parts[] = "Tratti da non cambiare mai: {$immutable}.";
        }
        if ($lookNotes !== '') {
            $parts[] = "Aspetto e presenza: {$lookNotes}.";
        }
        if ($stylingNotes !== '') {
            $parts[] = "Stile e presenza scenica: {$stylingNotes}.";
        }
        if ($promptNotes !== '') {
            $parts[] = "Indicazioni operative: {$promptNotes}.";
        }
        if (!empty((array) ($profile['shot_summary'] ?? []))) {
            $parts[] = 'Usa il pack multi-angolo come board identitaria dello stesso soggetto, non come persone diverse.';
        }
        if (trim((string) ($profile['reference_video_path'] ?? '')) !== '') {
            $parts[] = 'Il video reale di riferimento serve come guida per postura, mimica e presenza della stessa persona.';
        }
        $parts[] = 'Non cambiare identita del soggetto tra una generazione e l altra: varia scena e regia, non il volto.';

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 420, '');
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    private function buildSafeCommercialVideoPrompt(
        string $videoPrompt,
        string $briefRaw,
        array $referencePaths,
        array $assetVariables
    ): string {
        $source = $this->sanitizeVideoPromptForSafety($briefRaw !== '' ? $briefRaw : $videoPrompt, $assetVariables);
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $referenceCount = count(array_filter($referencePaths, fn ($path) => is_string($path) && $path !== ''));

        $parts = [
            'Crea un breve video verticale 9:16 realistico, pulito e adatto ai social.',
            'Una scena chiara per volta, movimenti morbidi, luce naturale e tono professionale.',
            'Niente nudita, niente sensualita, niente contatto ambiguo, niente focus insistito sul corpo, niente close-up estremi, niente testo o watermark.',
        ];

        if ($hasPersonVariable) {
            $parts[] = 'Se compare una persona di riferimento del brand, trattala come soggetto adulto e professionale, con abbigliamento adeguato, postura naturale e gesti rispettosi.';
        }

        if ($this->needsWellnessSafetyLanguage($source)) {
            $parts[] = 'Se il contesto e wellness o beauty, rappresentalo come trattamento professionale e rispettoso in un ambiente curato, senza sensualizzare la scena.';
        }

        if ($referenceCount > 1) {
            $parts[] = 'Se hai piu riferimenti, usali come scene coerenti e separate, non come collage o fusione impossibile.';
        }

        if ($source !== '') {
            $parts[] = 'Sintesi contenuto: ' . Str::limit($source, 240, '');
        }

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 760, '');
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    private function sanitizeVideoPromptForSafety(string $text, array $assetVariables): string
    {
        $sanitized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($sanitized === '') {
            return '';
        }

        foreach ($this->personVariableNames($assetVariables) as $name) {
            $pattern = '/\b' . preg_quote($name, '/') . '\b/ui';
            $sanitized = (string) preg_replace($pattern, 'la persona di riferimento del brand', $sanitized);
        }

        $replacements = [
            '/\bmassaggi?\s+tecnic[ioae]+\b/ui' => 'trattamento professionale',
            '/\bmassaggi?\b/ui' => 'trattamento benessere',
            '/\bmassage\b/ui' => 'wellness treatment',
            '/\bmassaging\b/ui' => 'performing a wellness treatment',
            '/\bschiena\b/ui' => 'parte alta del corpo',
            '/\bspalle\b/ui' => 'parte alta del corpo',
            '/\bback\b/ui' => 'upper body',
            '/\bshoulders\b/ui' => 'upper body',
            '/\btocco\b/ui' => 'gesto professionale',
            '/\btouch\b/ui' => 'professional technique',
            '/\bcliente\b/ui' => 'cliente adulto',
            '/\bclient\b/ui' => 'adult client',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $sanitized = (string) preg_replace($pattern, $replacement, $sanitized);
        }

        $sanitized = trim(preg_replace('/\s+/u', ' ', $sanitized) ?? $sanitized);

        return Str::limit($sanitized, 260, '');
    }

    private function isOpenAiVideoModerationBlock(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'blocked by our moderation system')
            || str_contains($message, 'moderation system')
            || str_contains($message, 'safety system')
            || str_contains($message, 'content policy')
            || str_contains($message, 'policy violation')
            || str_contains($message, 'disallowed content');
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    private function shouldUseOpenAiVideoModerationGuard(string $videoPrompt, string $briefRaw, array $assetVariables): bool
    {
        $source = trim($videoPrompt . ' ' . $briefRaw);
        if ($source === '') {
            return false;
        }

        if ($this->hasPersonAssetVariable($assetVariables) && $this->needsWellnessSafetyLanguage($source)) {
            return true;
        }

        $normalized = Str::lower($this->normalizeText($source));
        foreach (['spalle', 'schiena', 'touch', 'tocco', 'massagg', 'corpo', 'body'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    private function hasPersonAssetVariable(array $assetVariables): bool
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));

        foreach ($resolved as $row) {
            if (strtolower(trim((string) ($row['kind'] ?? 'custom'))) === 'person') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>|null
     */
    private function singleResolvedPersonVariable(array $assetVariables): ?array
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        $persons = array_values(array_filter(
            $resolved,
            fn ($row) => strtolower(trim((string) ($row['kind'] ?? 'custom'))) === 'person'
        ));

        return count($persons) === 1 ? $persons[0] : null;
    }

    private function videoSubjectContextText(array $meta, string $briefRaw = '', string $videoPrompt = ''): string
    {
        $parts = [
            trim($briefRaw),
            trim((string) data_get($meta, 'manual_brief', '')),
            trim($videoPrompt),
            trim((string) data_get($meta, 'video_prompt', '')),
            trim((string) data_get($meta, 'item_brain.angle', '')),
            trim((string) data_get($meta, 'editorial.angle', '')),
        ];

        return $this->normalizeText(implode(' ', array_values(array_unique(array_filter(
            $parts,
            fn ($value) => is_string($value) && trim($value) !== ''
        )))));
    }

    private function videoNeedsDualSubjectLock(array $meta, string $contextText, array $assetVariables): bool
    {
        if (!$this->hasPersonAssetVariable($assetVariables)) {
            return false;
        }

        return $this->primaryVideoProductLikeRow($meta, $assetVariables, $contextText) !== null;
    }

    private function subjectLockVideoInstruction(array $meta, string $contextText, array $assetVariables): string
    {
        if (!$this->videoNeedsDualSubjectLock($meta, $contextText, $assetVariables)) {
            return '';
        }

        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $contextText));
        if ($productLabel === '') {
            $productLabel = 'il veicolo o prodotto richiesto nel brief';
        }

        return "Vincolo di scena: la persona del brand e {$productLabel} devono restare entrambi soggetti principali del reel. Non trasformare il video in un ritratto della sola persona: {$productLabel} deve essere chiaramente visibile nel hook, nello sviluppo e nel payoff finale. Se il brief specifica marca, modello o colore, rispettali senza cambiarli.";
    }

    private function primaryVideoProductLikeRow(array $meta, array $assetVariables, string $contextText = ''): ?array
    {
        $candidates = [];

        foreach ((array) data_get($meta, 'asset_identity.slots', []) as $row) {
            if (is_array($row)) {
                $candidates[] = $row;
            }
        }

        foreach ($this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', [])) as $row) {
            if (is_array($row)) {
                $candidates[] = $row;
            }
        }

        foreach ($candidates as $row) {
            if ($this->rowLooksProductLike($row)) {
                return $row;
            }

            $kind = strtolower(trim((string) ($row['kind'] ?? 'custom')));
            if ($kind !== 'person' && $kind !== 'location' && $contextText !== '' && $this->assetVariableMatchesBrief($contextText, $row)) {
                return $row;
            }
        }

        if ($this->videoContextMentionsProduct($contextText)) {
            return [
                'name' => $this->extractProductHintFromContext($contextText),
                'kind' => 'product',
                'asset_role' => 'hero_product',
            ];
        }

        return null;
    }

    private function rowLooksProductLike(array $row): bool
    {
        $kind = strtolower(trim((string) ($row['kind'] ?? 'custom')));
        if ($kind === 'product') {
            return true;
        }

        $haystack = $this->normalizeText(implode(' ', array_filter([
            (string) ($row['name'] ?? ''),
            (string) ($row['slug'] ?? ''),
            (string) ($row['asset_role'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) data_get($row, 'profile.role', ''),
            (string) data_get($row, 'profile.identity_summary', ''),
            (string) data_get($row, 'profile.descriptor.summary', ''),
        ])));

        foreach (['product', 'prodotto', 'hero product', 'hero_product', 'vehicle', 'veicolo', 'auto', 'car', 'ferrari', 'lamborghini', 'porsche', 'mercedes', 'bmw', 'audi', 'alfa romeo', 'maserati', 'tesla', 'suv', 'coupe', 'spyder', 'supercar'] as $needle) {
            $needle = $this->normalizeText($needle);
            if ($needle !== '' && str_contains(' ' . $haystack . ' ', ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function videoContextMentionsProduct(string $contextText): bool
    {
        $contextText = $this->normalizeText($contextText);
        if ($contextText === '') {
            return false;
        }

        foreach (['ferrari', 'lamborghini', 'porsche', 'bmw', 'mercedes', 'audi', 'maserati', 'tesla', 'auto', 'macchina', 'car', 'vehicle', 'veicolo', 'prodotto', 'modello', 'supercar', 'suv', 'coupe', 'spyder', 'cabrio', 'roadster'] as $needle) {
            $needle = $this->normalizeText($needle);
            if ($needle !== '' && str_contains(' ' . $contextText . ' ', ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function extractProductHintFromContext(string $contextText): string
    {
        $normalized = $this->normalizeText($contextText);
        if ($normalized === '') {
            return '';
        }

        $brand = '';
        foreach (['ferrari', 'lamborghini', 'porsche', 'maserati', 'bmw', 'mercedes', 'audi', 'tesla', 'alfa romeo'] as $candidate) {
            $needle = $this->normalizeText($candidate);
            if ($needle !== '' && str_contains(' ' . $normalized . ' ', ' ' . $needle . ' ')) {
                $brand = $candidate;
                break;
            }
        }

        $color = '';
        foreach (['rossa', 'rosso', 'red', 'nera', 'nero', 'black', 'bianca', 'bianco', 'white', 'gialla', 'giallo', 'yellow', 'blu', 'blue', 'grigia', 'grigio', 'grey', 'gray'] as $candidate) {
            $needle = $this->normalizeText($candidate);
            if ($needle !== '' && str_contains(' ' . $normalized . ' ', ' ' . $needle . ' ')) {
                $color = $candidate;
                break;
            }
        }

        if ($brand !== '' && $color !== '') {
            return trim($brand . ' ' . $color);
        }
        if ($brand !== '') {
            return $brand;
        }
        if ($color !== '') {
            return 'auto ' . $color;
        }
        if ($this->videoContextMentionsProduct($normalized)) {
            return 'il veicolo o prodotto richiesto nel brief';
        }

        return '';
    }

    private function productLikeRowName(?array $row): string
    {
        if (!is_array($row)) {
            return '';
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $descriptor = trim((string) (
            ($row['description'] ?? '')
            ?: data_get($row, 'profile.identity_summary', '')
            ?: data_get($row, 'profile.descriptor.summary', '')
        ));

        return $descriptor;
    }

    private function needsWellnessSafetyLanguage(string $text): bool
    {
        $normalized = Str::lower($this->normalizeText($text));

        foreach (['massagg', 'trattamento', 'wellness', 'spa', 'olist', 'beauty', 'benessere'] as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @return array<int, string>
     */
    private function personVariableNames(array $assetVariables): array
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));

        return collect($resolved)
            ->filter(fn ($row) => strtolower(trim((string) ($row['kind'] ?? 'custom'))) === 'person')
            ->map(fn ($row) => trim((string) ($row['name'] ?? '')))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $imageReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<string, mixed>  $assetVariables
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function prioritizeVideoReferencePoolsForPersonVariable(
        array $imageReferenceAbsPool,
        array $imageReferencePathPool,
        array $assetVariables,
        array $meta = [],
        string $contextText = ''
    ): array {
        if ($this->videoNeedsDualSubjectLock($meta, $contextText, $assetVariables)) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $row = $this->singleResolvedPersonVariable($assetVariables);
        if ($row === null) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $pathToAbs = [];
        foreach ($imageReferencePathPool as $index => $path) {
            $path = trim((string) $path);
            $abs = isset($imageReferenceAbsPool[$index]) ? trim((string) $imageReferenceAbsPool[$index]) : '';
            if ($path === '' || $abs === '') {
                continue;
            }
            $pathToAbs[$path] = $abs;
        }

        if (empty($pathToAbs)) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $orderedPaths = $this->orderedPersonImagePaths($row, array_keys($pathToAbs));
        if (empty($orderedPaths)) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $orderedAbs = array_values(array_map(fn ($path) => $pathToAbs[$path], $orderedPaths));

        return [$orderedAbs, $orderedPaths];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $referencePaths
     */
    private function shouldUsePersonIdentityReferenceBoard(array $assetVariables, array $referencePaths): bool
    {
        return $this->singleResolvedPersonVariable($assetVariables) !== null
            && count(array_values(array_filter($referencePaths, fn ($path) => is_string($path) && trim($path) !== ''))) >= 2;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $availablePaths
     * @return array<int, string>
     */
    private function orderedPersonImagePaths(array $row, array $availablePaths): array
    {
        $availableLookup = array_fill_keys($availablePaths, true);
        $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
        $shotSummary = is_array($profile['shot_summary'] ?? null) ? $profile['shot_summary'] : [];

        $slotPriority = [
            'front',
            'three_quarter_left',
            'three_quarter_right',
            'half_body',
            'profile',
        ];

        $ordered = [];
        foreach ($slotPriority as $slot) {
            foreach ($shotSummary as $shot) {
                if (!is_array($shot)) {
                    continue;
                }
                if (trim((string) ($shot['slot'] ?? '')) !== $slot) {
                    continue;
                }
                $path = trim((string) ($shot['path'] ?? ''));
                if ($path !== '' && isset($availableLookup[$path])) {
                    $ordered[] = $path;
                    unset($availableLookup[$path]);
                }
            }
        }

        foreach ($availablePaths as $path) {
            if (isset($availableLookup[$path])) {
                $ordered[] = $path;
                unset($availableLookup[$path]);
            }
        }

        return array_values(array_unique($ordered));
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function filterReferenceImagePaths(array $paths): array
    {
        $disk = Storage::disk('public');
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif'];

        return array_values(array_filter(
            array_unique(array_map(fn ($path) => trim((string) $path), $paths)),
            function (string $path) use ($disk, $allowed): bool {
                if ($path === '' || !$disk->exists($path)) {
                    return false;
                }

                $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

                return in_array($extension, $allowed, true);
            }
        ));
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
        NanoBananaService $nanoBanana,
        string $brief,
        string $prompt,
        array $referenceAbsPaths,
        array $assetVariables
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
            $composePrompt .= ' Fai una vera fusione narrativa e visiva in un unica scena plausibile: niente collage, niente split-screen, niente mosaico, niente overlay meccanico.';
            $composePrompt .= ' Unifica luce, prospettiva, styling e ambientazione come se fosse un solo scatto strategico.';
            $composePrompt .= ' ' . $this->locationEnvelopePreservationInstruction($assetVariables, []);
            $composePrompt .= ' Niente testo, niente watermark, niente loghi inventati.';
            $composePrompt .= ' Brief: ' . Str::limit(trim($brief !== '' ? $brief : $prompt), 500, '');
            $composePrompt .= ' Direzione creativa: ' . Str::limit(trim($prompt), 380, '');

            if (!empty($missingHint)) {
                $composePrompt .= ' Nel tentativo precedente mancavano alcuni soggetti: riferimenti #' . implode(', #', $missingHint) . '.';
                $composePrompt .= ' In questo tentativo devono essere visibili tutti i soggetti mancanti.';
            }

            try {
                $img = $nanoBanana->generateImageEditBase64($composePrompt, $refs);
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

    /**
     * @return array<string, mixed>
     */
    private function resolveAssetVariableContext(array $meta, array $strategy): array
    {
        $metaPayload = (array) data_get($meta, 'asset_variables', []);

        $catalog = $this->normalizeAssetVariableRows((array) data_get($metaPayload, 'catalog', []));
        if (empty($catalog)) {
            $catalog = $this->normalizeAssetVariableRows((array) data_get($meta, 'asset_variables_catalog', []));
        }
        if (empty($catalog)) {
            $catalog = $this->normalizeAssetVariableRows((array) data_get($strategy, 'brand_references.asset_variables', []));
        }

        $requestedIds = collect((array) data_get($metaPayload, 'requested_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $detectedIds = collect((array) data_get($metaPayload, 'detected_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $recognizedTerms = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            (array) data_get($metaPayload, 'recognized_terms', [])
        ))));

        $resolved = $this->normalizeAssetVariableRows((array) data_get($metaPayload, 'resolved', []));

        if (empty($resolved) && !empty($catalog) && (!empty($requestedIds) || !empty($detectedIds))) {
            $catalogById = collect($catalog)->keyBy(fn ($row) => (int) ($row['id'] ?? 0));
            $resolved = collect(array_merge($requestedIds, $detectedIds))
                ->map(fn (int $id) => $catalogById->get($id))
                ->filter()
                ->values()
                ->all();
        }

        $brief = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        if ($brief !== '' && !empty($catalog)) {
            foreach ($catalog as $row) {
                if (!$this->assetVariableMatchesBrief($brief, $row)) {
                    continue;
                }

                $resolved[] = $row;
                $detectedId = (int) ($row['id'] ?? 0);
                if ($detectedId > 0) {
                    $detectedIds[] = $detectedId;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $recognizedTerms[] = $name;
                }
            }
        }

        $resolved = $this->normalizeAssetVariableRows($resolved);

        $resolvedIds = collect($resolved)
            ->map(fn ($row) => (int) ($row['id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $resolvedAssetIds = collect($resolved)
            ->flatMap(fn ($row) => (array) ($row['asset_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $resolvedAssetPaths = collect($resolved)
            ->flatMap(fn ($row) => (array) ($row['asset_paths'] ?? []))
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn (string $path) => $path !== '')
            ->unique()
            ->values()
            ->all();

        $selectionMode = (string) data_get($metaPayload, 'selection_mode', '');
        if ($selectionMode === '' || $selectionMode === 'none') {
            $selectionMode = $this->assetVariableSelectionMode(
                !empty($requestedIds),
                !empty($detectedIds)
            );
        }

        return [
            'catalog' => $catalog,
            'requested_ids' => array_values(array_unique($requestedIds)),
            'detected_ids' => array_values(array_unique($detectedIds)),
            'resolved_ids' => $resolvedIds,
            'resolved_asset_ids' => $resolvedAssetIds,
            'resolved_asset_paths' => $resolvedAssetPaths,
            'resolved' => $resolved,
            'recognized_terms' => array_values(array_unique(array_filter($recognizedTerms))),
            'selection_mode' => $selectionMode,
        ];
    }

    /**
     * Normalizza il payload presenter/product/place senza perdere i riferimenti risolti.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>
     */
    private function resolveAssetIdentityContext(array $meta, array $assetVariables): array
    {
        $payload = (array) data_get($meta, 'asset_identity', []);
        $resolved = collect($this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', [])));
        $slots = [];

        foreach ((array) data_get($payload, 'slots', []) as $slot => $row) {
            if (!is_array($row)) {
                continue;
            }

            $resolvedRow = $resolved->first(fn ($variable) => (int) ($variable['id'] ?? 0) === (int) ($row['id'] ?? 0));
            $merged = is_array($resolvedRow) ? array_replace($resolvedRow, $row) : $row;
            $slots[(string) $slot] = $merged;
        }

        return [
            'slots' => $slots,
            'slot_ids' => collect((array) data_get($payload, 'slot_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all(),
            'seasonal_overlay' => trim((string) data_get($payload, 'seasonal_overlay', '')),
            'consistency_mode' => trim((string) data_get($payload, 'consistency_mode', 'balanced')),
            'locked_elements' => collect((array) data_get($payload, 'locked_elements', []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->unique()
                ->values()
                ->all(),
            'allowed_changes' => collect((array) data_get($payload, 'allowed_changes', []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    private function buildAssetVariablePromptHint(array $assetVariables): string
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        if (empty($resolved)) {
            return '';
        }

        $parts = [];
        foreach (array_slice($resolved, 0, 4) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            $kind = trim((string) ($row['kind'] ?? 'custom'));
            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];

            $label = $name !== '' ? $name : ($slug !== '' ? '@' . $slug : 'variabile');
            if ($kind !== '') {
                $label .= ' [' . $kind . ']';
            }
            $assetRole = trim((string) ($row['asset_role'] ?? ''));
            if ($assetRole !== '') {
                $label .= ' role: ' . $assetRole;
            }
            $identityMode = trim((string) ($row['identity_mode'] ?? ''));
            if ($identityMode !== '') {
                $label .= ' mode: ' . $identityMode;
            }
            $threshold = isset($row['consistency_threshold']) ? (int) $row['consistency_threshold'] : 0;
            if ($threshold > 0) {
                $label .= ' soglia: ' . $threshold;
            }

            $refs = collect((array) ($row['asset_paths'] ?? []))
                ->map(fn ($path) => trim((string) basename((string) $path)))
                ->filter(fn (string $v) => $v !== '')
                ->take(2)
                ->values()
                ->all();

            if (!empty($refs)) {
                $label .= ' refs: ' . implode(', ', $refs);
            }

            if ($kind === 'person' && !empty($profile)) {
                $role = trim((string) ($profile['role'] ?? ''));
                $immutable = trim((string) ($profile['immutable_traits'] ?? ''));
                $lookNotes = trim((string) ($profile['look_notes'] ?? ''));

                if ($role !== '') {
                    $label .= ' ruolo: ' . Str::limit($role, 80, '');
                }
                if ($immutable !== '') {
                    $label .= ' non cambiare: ' . Str::limit($immutable, 120, '');
                }
                if ($lookNotes !== '' && $immutable === '') {
                    $label .= ' look: ' . Str::limit($lookNotes, 100, '');
                }
            }

            $locked = trim((string) data_get($profile, 'prompt_lock.immutable_elements', ''));
            if ($locked !== '' && $kind !== 'person') {
                $label .= ' non cambiare: ' . Str::limit($locked, 120, '');
            }

            $allowedTransforms = collect((array) data_get($profile, 'allowed_transforms', []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(3)
                ->values()
                ->all();
            if (!empty($allowedTransforms)) {
                $label .= ' cambia solo: ' . implode(', ', $allowedTransforms);
            }

            $parts[] = $label;
        }

        return Str::limit(implode('; ', $parts), 520, '');
    }

    /**
     * Questo hint e piu sintetico e specifico per il singolo contenuto in costruzione.
     *
     * @param  array<string, mixed>  $assetIdentity
     */
    private function buildAssetIdentityPromptHint(array $assetIdentity): string
    {
        $slots = is_array($assetIdentity['slots'] ?? null) ? $assetIdentity['slots'] : [];
        if (empty($slots) && empty((array) ($assetIdentity['locked_elements'] ?? [])) && trim((string) ($assetIdentity['seasonal_overlay'] ?? '')) === '') {
            return '';
        }

        $parts = [];

        foreach ($slots as $slot => $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $label = $slot . ': ' . $name;
            $descriptor = trim((string) data_get($row, 'descriptor.summary', data_get($row, 'profile.descriptor.summary', '')));
            if ($descriptor !== '') {
                $label .= ' (' . Str::limit($descriptor, 90, '') . ')';
            }
            $locked = collect((array) ($row['locked_elements'] ?? []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(1)
                ->values()
                ->all();
            if (!empty($locked)) {
                $label .= ' fisso: ' . Str::limit($locked[0], 100, '');
            }

            $parts[] = $label;
        }

        $seasonalOverlay = trim((string) ($assetIdentity['seasonal_overlay'] ?? ''));
        if ($seasonalOverlay !== '') {
            $parts[] = 'overlay: ' . Str::limit($seasonalOverlay, 80, '');
        }

        $allowedChanges = collect((array) ($assetIdentity['allowed_changes'] ?? []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->take(4)
            ->values()
            ->all();
        if (!empty($allowedChanges)) {
            $parts[] = 'cambi ammessi: ' . implode(', ', $allowedChanges);
        }

        $consistencyMode = trim((string) ($assetIdentity['consistency_mode'] ?? ''));
        if ($consistencyMode !== '') {
            $parts[] = 'consistency: ' . $consistencyMode;
        }

        return Str::limit(implode('; ', $parts), 520, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetVariableRows(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) ? (int) $row['id'] : null;
            $name = trim((string) ($row['name'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? Str::slug($name)));
            if ($name === '' && $slug === '') {
                continue;
            }

            $key = ($id && $id > 0) ? ('id:' . $id) : ('slug:' . Str::lower($slug));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $assetIds = collect((array) ($row['asset_ids'] ?? []))
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values()
                ->all();

            $assetPaths = collect((array) ($row['asset_paths'] ?? []))
                ->map(fn ($path) => trim((string) $path))
                ->filter(fn (string $path) => $path !== '')
                ->unique()
                ->values()
                ->all();

            if (empty($assetPaths) && !empty($row['assets']) && is_array($row['assets'])) {
                $assetPaths = collect((array) $row['assets'])
                    ->map(fn ($asset) => is_array($asset) ? trim((string) ($asset['path'] ?? '')) : '')
                    ->filter(fn (string $path) => $path !== '')
                    ->unique()
                    ->values()
                    ->all();
            }

            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
            $voiceAssetId = isset($row['voice_asset_id']) ? (int) $row['voice_asset_id'] : (int) data_get($profile, 'voice_reference.sample_asset_id', 0);
            $voiceAssetPath = trim((string) ($row['voice_asset_path'] ?? data_get($profile, 'voice_reference.sample_path', '')));
            $voiceAssetName = trim((string) ($row['voice_asset_name'] ?? data_get($profile, 'voice_reference.sample_name', '')));
            $voiceProvider = trim((string) ($row['voice_provider'] ?? data_get($profile, 'voice_reference.provider', '')));
            $voiceProviderVoiceId = trim((string) ($row['voice_provider_voice_id'] ?? data_get($profile, 'voice_reference.provider_voice_id', '')));
            $voiceStatus = trim((string) ($row['voice_status'] ?? data_get($profile, 'voice_reference.status', '')));

            $out[] = [
                'id' => $id && $id > 0 ? $id : null,
                'name' => $name,
                'slug' => $slug,
                'kind' => trim((string) ($row['kind'] ?? 'custom')),
                'asset_role' => trim((string) ($row['asset_role'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'canonical_asset_id' => isset($row['canonical_asset_id']) ? (int) $row['canonical_asset_id'] : null,
                'canonical_asset_path' => trim((string) ($row['canonical_asset_path'] ?? '')),
                'voice_asset_id' => $voiceAssetId > 0 ? $voiceAssetId : null,
                'voice_asset_path' => $voiceAssetPath,
                'voice_asset_name' => $voiceAssetName,
                'voice_provider' => $voiceProvider,
                'voice_provider_voice_id' => $voiceProviderVoiceId,
                'voice_status' => $voiceStatus,
                'identity_mode' => trim((string) ($row['identity_mode'] ?? 'balanced')),
                'consistency_threshold' => isset($row['consistency_threshold']) ? (int) $row['consistency_threshold'] : null,
                'profile' => $profile,
                'asset_ids' => $assetIds,
                'asset_paths' => $assetPaths,
                'assets' => is_array($row['assets'] ?? null) ? $row['assets'] : [],
                'voice_asset' => is_array($row['voice_asset'] ?? null) ? $row['voice_asset'] : null,
            ];
        }

        return array_values($out);
    }
    /**
     * @param  array<string, mixed>  $row
     */
    private function assetVariableMatchesBrief(string $briefNormalized, array $row): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        $name = $this->normalizeText((string) ($row['name'] ?? ''));
        $slug = $this->normalizeText(str_replace('-', ' ', (string) ($row['slug'] ?? '')));
        $profileText = $this->normalizeText(implode(' ', array_filter([
            (string) ($row['asset_role'] ?? ''),
            (string) data_get($row, 'profile.role', ''),
            (string) data_get($row, 'profile.identity_summary', ''),
            (string) data_get($row, 'profile.immutable_traits', ''),
            (string) data_get($row, 'profile.descriptor.summary', ''),
            (string) data_get($row, 'profile.prompt_lock.immutable_elements', ''),
            implode(' ', (array) data_get($row, 'profile.allowed_transforms', [])),
        ])));

        if ($name !== '' && str_contains(' ' . $briefNormalized . ' ', ' ' . $name . ' ')) {
            return true;
        }

        if ($slug !== '' && str_contains(' ' . $briefNormalized . ' ', ' ' . $slug . ' ')) {
            return true;
        }

        $slugCompact = str_replace(' ', '', $slug);
        if ($slugCompact !== '' && str_contains($briefNormalized, '@' . $slugCompact)) {
            return true;
        }

        $nameCompact = str_replace(' ', '', $name);
        if ($nameCompact !== '' && str_contains($briefNormalized, '@' . $nameCompact)) {
            return true;
        }

        if ($profileText !== '' && str_contains(' ' . $briefNormalized . ' ', ' ' . $profileText . ' ')) {
            return true;
        }

        return false;
    }

    private function assetVariableSelectionMode(bool $hasRequested, bool $hasDetected): string
    {
        if ($hasRequested && $hasDetected) {
            return 'manual+brief';
        }
        if ($hasRequested) {
            return 'manual';
        }
        if ($hasDetected) {
            return 'brief';
        }
        return 'none';
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
        $variablePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'asset_variables.resolved_asset_paths', [])
        )));
        if (empty($variablePaths)) {
            $variablePaths = collect((array) data_get($meta, 'asset_variables.resolved', []))
                ->flatMap(fn ($row) => is_array($row) ? (array) ($row['asset_paths'] ?? []) : [])
                ->map(fn ($path) => (string) $path)
                ->filter(fn ($path) => $path !== '')
                ->values()
                ->all();
        }
        if (!empty($variablePaths)) {
            $paths = array_values(array_unique(array_merge($paths, $variablePaths)));
        }

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

    /**
     * @param  array<string, mixed>  $itemBrain
     * @param  array<int, string>  $planTitles
     * @param  array<int, string>  $planCaptions
     * @return array<string, mixed>
     */
    private function buildSocialPublicationContext(
        ContentItem $item,
        array $itemBrain,
        array $planTitles,
        array $planCaptions
    ): array {
        return [
            'is_social_post' => true,
            'platforms' => $item->platforms(),
            'format' => (string) ($item->format ?? 'post'),
            'feed_position' => $this->positionInPlan($item) + 1,
            'feed_total' => max(1, $this->totalItemsInPlan($item)),
            'series_name' => (string) data_get($itemBrain, 'series_name', ''),
            'series_step' => data_get($itemBrain, 'series_step'),
            'connection_hint' => (string) data_get($itemBrain, 'connection_hint', ''),
            'standalone_rule' => (string) data_get($itemBrain, 'standalone_rule', ''),
            'goal' => 'Creare un contenuto pensato per il feed social: chiaro, memorabile, fermascroll e coerente con l insieme delle pubblicazioni.',
            'nearby_titles' => array_values(array_slice($planTitles, 0, 5)),
            'nearby_captions' => array_values(array_slice($planCaptions, 0, 4)),
        ];
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

    /**
     * Arricchisce il video con una traccia audio quando assente:
     * 1) prova audio da brand video reference
     * 2) fallback TTS da caption/titolo
     *
     * @return array{
     *   applied:bool,
     *   reason:string,
     *   source:string|null,
     *   video_path:string|null,
     *   audio_path:string|null,
     *   error:string|null
     * }
     */
    private function maybeAttachAudioTrackToVideo(ContentItem $item, string $videoPath, SpeechSynthesisService $speechSynthesis): array
    {
        if (!(bool) config('generation.video_auto_audio', true)) {
            return [
                'applied' => false,
                'reason' => 'video_auto_audio_disabled',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $videoPath = trim($videoPath);
        if ($videoPath === '') {
            return [
                'applied' => false,
                'reason' => 'missing_video_path',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => null,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $publicDisk = Storage::disk('public');
        if (!$publicDisk->exists($videoPath)) {
            return [
                'applied' => false,
                'reason' => 'video_file_not_found',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $ffmpeg = $this->resolveFfmpegBinary();
        $ffprobe = $this->resolveFfprobeBinary($ffmpeg);
        if (!$this->canRunBinary($ffmpeg)) {
            return [
                'applied' => false,
                'reason' => 'ffmpeg_unavailable',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => 'FFmpeg non disponibile sul server',
            ];
        }
        $ffprobeAvailable = $this->canRunBinary($ffprobe);

        $currentProvider = strtolower(trim((string) data_get($item->ai_meta, 'video_provider', '')));
        if (!$ffprobeAvailable && $currentProvider !== 'runway') {
            return [
                'applied' => false,
                'reason' => 'ffprobe_unavailable_skip_non_runway',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => 'FFprobe non disponibile sul server',
            ];
        }

        $videoAbsPath = $publicDisk->path($videoPath);
        if ($ffprobeAvailable && $this->videoHasAudioStream($videoAbsPath, $ffprobe)) {
            return [
                'applied' => false,
                'reason' => 'video_already_has_audio',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $tempAudioAbs = null;
        $tempMuxedAbs = null;
        $storedAudioPath = null;
        $source = null;
        $provider = null;
        $voiceId = null;
        $voiceLabel = null;
        $error = null;
        $narration = $this->resolveNarrationTextForVideo($item);

        try {
            if ($narration !== '') {
                $voiceContext = $this->resolvePersonaVoiceContext($item);
                if (!empty($voiceContext)) {
                    try {
                        $voiceVariable = $this->resolvePersonaVoiceVariable($item);
                        $speechPayload = $speechSynthesis->synthesizeWithVoiceContext(
                            text: $narration,
                            variable: $voiceVariable,
                            voiceContext: $voiceContext,
                            providerMatrix: (array) data_get($item->ai_meta, 'provider_matrix', [])
                        );

                        if (is_array($speechPayload)) {
                            $stored = $this->storeGeneratedAudioPayload($publicDisk, $speechPayload);
                            if (!empty($stored['absolute_path'])) {
                                $tempAudioAbs = (string) $stored['absolute_path'];
                                $storedAudioPath = (string) ($stored['path'] ?? null);
                                $source = (string) ($speechPayload['source'] ?? 'persona_voice_clone');
                                $provider = (string) ($speechPayload['provider'] ?? '');
                                $voiceId = isset($speechPayload['voice_id']) ? (string) $speechPayload['voice_id'] : null;
                                $voiceLabel = isset($speechPayload['label']) ? (string) $speechPayload['label'] : null;
                            }
                        }
                    } catch (Throwable $ttsError) {
                        $error = Str::limit($ttsError->getMessage(), 240, '');
                    }
                }
            }

            if ($tempAudioAbs === null) {
                $brandVideoRefPath = $this->resolveBrandVideoReferencePath($item);
                if ($brandVideoRefPath !== '' && $publicDisk->exists($brandVideoRefPath)) {
                    $brandVideoAbs = $publicDisk->path($brandVideoRefPath);
                    if (!$ffprobeAvailable || $this->videoHasAudioStream($brandVideoAbs, $ffprobe)) {
                        $extractedAbs = $tmpDir . DIRECTORY_SEPARATOR . 'brand-audio-' . Str::uuid()->toString() . '.m4a';
                        if ($this->extractAudioTrackFromVideo($brandVideoAbs, $extractedAbs, $ffmpeg)) {
                            $tempAudioAbs = $extractedAbs;
                            $source = 'brand_video_audio';
                            $provider = null;
                            $voiceId = null;
                            $voiceLabel = 'Audio reale da video brand';
                        }
                    }
                }
            }

            if ($tempAudioAbs === null && $narration !== '') {
                try {
                    $speechPayload = $speechSynthesis->synthesizeWithDefaultVoice($narration);
                    if (is_array($speechPayload)) {
                        $stored = $this->storeGeneratedAudioPayload($publicDisk, $speechPayload);
                        if (!empty($stored['absolute_path'])) {
                            $tempAudioAbs = (string) $stored['absolute_path'];
                            $storedAudioPath = (string) ($stored['path'] ?? null);
                            $source = (string) ($speechPayload['source'] ?? 'openai_tts');
                            $provider = (string) ($speechPayload['provider'] ?? '');
                            $voiceId = isset($speechPayload['voice_id']) ? (string) $speechPayload['voice_id'] : null;
                            $voiceLabel = isset($speechPayload['label']) ? (string) $speechPayload['label'] : null;
                        }
                    }
                } catch (Throwable $ttsError) {
                    $error = Str::limit($ttsError->getMessage(), 240, '');
                }
            }

            if ($tempAudioAbs === null || !is_file($tempAudioAbs)) {
                return [
                    'applied' => false,
                    'reason' => 'no_audio_source_available',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error,
                ];
            }

            $tempMuxedAbs = $tmpDir . DIRECTORY_SEPARATOR . 'video-audio-' . Str::uuid()->toString() . '.mp4';
            if (!$this->muxVideoWithAudioTrack($videoAbsPath, $tempAudioAbs, $tempMuxedAbs, $ffmpeg)) {
                return [
                    'applied' => false,
                    'reason' => 'mux_failed',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error ?: 'FFmpeg non � riuscito ad agganciare l audio al video',
                ];
            }

            $newVideoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.mp4';
            $bytes = @file_get_contents($tempMuxedAbs);
            if (!is_string($bytes) || $bytes === '') {
                return [
                    'applied' => false,
                    'reason' => 'mux_output_empty',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error,
                ];
            }

            $publicDisk->put($newVideoPath, $bytes);
            if (!$publicDisk->exists($newVideoPath)) {
                return [
                    'applied' => false,
                    'reason' => 'mux_output_store_failed',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error,
                ];
            }

            return [
                'applied' => true,
                'reason' => 'audio_attached',
                'source' => $source,
                'provider' => $provider,
                'voice_id' => $voiceId,
                'voice_label' => $voiceLabel,
                'video_path' => $newVideoPath,
                'audio_path' => $storedAudioPath,
                'error' => $error,
            ];
        } catch (Throwable $e) {
            return [
                'applied' => false,
                'reason' => 'audio_attach_exception',
                'source' => $source,
                'provider' => $provider,
                'voice_id' => $voiceId,
                'voice_label' => $voiceLabel,
                'video_path' => $videoPath,
                'audio_path' => $storedAudioPath,
                'error' => Str::limit($e->getMessage(), 240, ''),
            ];
        } finally {
            if (is_string($tempMuxedAbs) && $tempMuxedAbs !== '' && is_file($tempMuxedAbs)) {
                @unlink($tempMuxedAbs);
            }

            if (
                is_string($tempAudioAbs)
                && $tempAudioAbs !== ''
                && is_file($tempAudioAbs)
                && ($storedAudioPath === null || !str_contains(str_replace('\\', '/', $tempAudioAbs), '/storage/app/public/'))
            ) {
                @unlink($tempAudioAbs);
            }
        }
    }
    private function resolveNarrationTextForVideo(ContentItem $item): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $voiceover = trim((string) data_get($meta, 'video_voiceover', ''));
        if ($voiceover !== '') {
            return $this->sanitizeNarrationText($voiceover);
        }

        $caption = trim((string) ($item->ai_caption ?? ''));
        if ($caption === '') {
            $caption = trim((string) ($item->caption ?? ''));
        }

        if ($caption !== '') {
            return $this->sanitizeNarrationText($this->compactCaptionForVoiceover($caption));
        }

        return '';
    }

    private function sanitizeNarrationText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/#\w+/u', '', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/iu', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $maxChars = (int) (config('openai.speech_max_chars') ?: 1000);
        $maxChars = max(200, min(4000, $maxChars));
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = trim(mb_substr($text, 0, $maxChars, 'UTF-8'));
        }

        return $text;
    }

    private function compactCaptionForVoiceover(string $caption): string
    {
        $text = trim($caption);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^[\p{So}\p{Sk}\p{Cf}\s]+/u', '', $text) ?? $text;
        $text = preg_replace('/\b(contesto|azione|risultato|follow-up)\s*:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/#\w+/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: [];
        $clean = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if (preg_match('/\b(contattaci|scrivici|prenota|clicca|salva il post|seguici)\b/iu', $sentence) === 1) {
                continue;
            }

            $clean[] = $sentence;
            if (count($clean) >= 2) {
                break;
            }
        }

        return trim(implode(' ', $clean));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path:?string, absolute_path:?string}
     */
    private function storeGeneratedAudioPayload($publicDisk, array $payload): array
    {
        $bytes = $payload['bytes'] ?? null;
        if (!is_string($bytes) || $bytes === '') {
            return ['path' => null, 'absolute_path' => null];
        }

        $extension = strtolower(trim((string) ($payload['extension'] ?? 'mp3')));
        if ($extension === '' || preg_match('/^[a-z0-9]{2,5}$/', $extension) !== 1) {
            $extension = 'mp3';
        }

        $path = 'ai/audio/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $extension;
        $publicDisk->put($path, $bytes);
        if (!$publicDisk->exists($path)) {
            return ['path' => null, 'absolute_path' => null];
        }

        return [
            'path' => $path,
            'absolute_path' => $publicDisk->path($path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePersonaVoiceContext(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $candidates = [];

        $presenter = data_get($meta, 'asset_identity.slots.presenter', null);
        if (is_array($presenter)) {
            $candidates[] = $presenter;
        }

        foreach ($this->normalizeAssetVariableRows((array) data_get($meta, 'asset_variables.resolved', [])) as $row) {
            if (strtolower(trim((string) ($row['kind'] ?? 'custom'))) !== 'person') {
                continue;
            }

            $candidates[] = $row;
        }

        foreach ($candidates as $row) {
            if (!is_array($row)) {
                continue;
            }

            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
            $assetPath = trim((string) ($row['voice_asset_path'] ?? data_get($profile, 'voice_reference.sample_path', '')));
            $providerVoiceId = trim((string) ($row['voice_provider_voice_id'] ?? data_get($profile, 'voice_reference.provider_voice_id', '')));
            if ($assetPath === '' && $providerVoiceId === '') {
                continue;
            }

            return [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => trim((string) ($row['name'] ?? '')),
                'description' => trim((string) (($row['description'] ?? '') ?: data_get($profile, 'identity_summary', ''))),
                'asset_path' => $assetPath,
                'asset_name' => trim((string) ($row['voice_asset_name'] ?? data_get($profile, 'voice_reference.sample_name', ''))),
                'provider' => trim((string) ($row['voice_provider'] ?? data_get($profile, 'voice_reference.provider', ''))),
                'provider_voice_id' => $providerVoiceId,
                'status' => trim((string) ($row['voice_status'] ?? data_get($profile, 'voice_reference.status', ''))),
                'label' => trim((string) data_get($profile, 'voice_reference.label', 'Voce persona')),
            ];
        }

        return [];
    }

    private function resolvePersonaVoiceVariable(ContentItem $item): ?AssetVariable
    {
        $voiceContext = $this->resolvePersonaVoiceContext($item);
        $variableId = (int) ($voiceContext['id'] ?? 0);
        if ($variableId < 1) {
            return null;
        }

        return AssetVariable::query()
            ->where('tenant_id', $item->tenant_id)
            ->find($variableId);
    }
    private function resolveBrandVideoReferencePath(ContentItem $item): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $candidate = trim((string) data_get($meta, 'video_reference.path', ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $assets = is_array($item->assets) ? $item->assets : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            if ($type === 'brand_video') {
                return $path;
            }
        }

        return '';
    }

    private function extractAudioTrackFromVideo(string $sourceVideoAbs, string $targetAudioAbs, string $ffmpegBinary): bool
    {
        $process = new Process([
            $ffmpegBinary,
            '-y',
            '-i',
            $sourceVideoAbs,
            '-vn',
            '-acodec',
            'aac',
            '-b:a',
            '160k',
            $targetAudioAbs,
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful() && is_file($targetAudioAbs) && filesize($targetAudioAbs) > 0;
    }

    private function muxVideoWithAudioTrack(string $sourceVideoAbs, string $audioAbs, string $targetVideoAbs, string $ffmpegBinary): bool
    {
        $process = new Process([
            $ffmpegBinary,
            '-y',
            '-i',
            $sourceVideoAbs,
            '-i',
            $audioAbs,
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '22',
            '-c:a',
            'aac',
            '-b:a',
            '160k',
            '-af',
            'apad',
            '-shortest',
            $targetVideoAbs,
        ]);
        $process->setTimeout(240);
        $process->run();

        return $process->isSuccessful() && is_file($targetVideoAbs) && filesize($targetVideoAbs) > 0;
    }

    private function videoHasAudioStream(string $videoAbsPath, string $ffprobeBinary): bool
    {
        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_entries',
            'stream=codec_type',
            '-of',
            'csv=p=0',
            $videoAbsPath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        $output = strtolower(trim((string) $process->getOutput()));
        return $output !== '' && str_contains($output, 'audio');
    }

    private function resolveFfmpegBinary(): string
    {
        $configured = trim((string) config('generation.ffmpeg_binary', ''));
        return $configured !== '' ? $configured : 'ffmpeg';
    }

    private function resolveFfprobeBinary(string $ffmpegBinary): string
    {
        $configured = trim((string) config('generation.ffprobe_binary', ''));
        if ($configured !== '') {
            return $configured;
        }

        $normalized = str_replace('\\', '/', $ffmpegBinary);
        if (str_ends_with(strtolower($normalized), '/ffmpeg.exe')) {
            return substr($ffmpegBinary, 0, -10) . 'ffprobe.exe';
        }
        if (str_ends_with(strtolower($normalized), '/ffmpeg')) {
            return substr($ffmpegBinary, 0, -6) . 'ffprobe';
        }

        return 'ffprobe';
    }

    private function canRunBinary(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(6);
            $process->run();
            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function attachBrandVideoReference(ContentItem $item): void
    {
        $format = strtolower((string) ($item->format ?? ''));
        if (!in_array($format, ['reel', 'story', 'video'], true)) {
            return;
        }

        $videoPath = '';
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $resolvedVariables = $this->normalizeAssetVariableRows((array) data_get($meta, 'asset_variables.resolved', []));

        foreach ($resolvedVariables as $row) {
            if (strtolower(trim((string) ($row['kind'] ?? 'custom'))) !== 'person') {
                continue;
            }

            $candidate = trim((string) data_get($row, 'profile.reference_video_path', ''));
            if ($candidate === '' && !empty($row['assets']) && is_array($row['assets'])) {
                foreach ((array) $row['assets'] as $asset) {
                    if (!is_array($asset)) {
                        continue;
                    }

                    $assetKind = strtolower(trim((string) ($asset['kind'] ?? '')));
                    $assetPath = trim((string) ($asset['path'] ?? ''));
                    if ($assetKind === 'video' && $assetPath !== '') {
                        $candidate = $assetPath;
                        break;
                    }
                }
            }

            if ($candidate !== '') {
                $videoPath = $candidate;
                break;
            }
        }

        if ($videoPath === '') {
            $videoPath = BrandAsset::query()
                ->where('tenant_id', $item->tenant_id)
                ->whereNull('content_plan_id')
                ->where('kind', 'video')
                ->orderBy('id')
                ->value('path');
        }

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

    private function hasGeneratedVisualOutput(ContentItem $item): bool
    {
        $imagePath = trim((string) ($item->ai_image_path ?? ''));
        if ($imagePath !== '') {
            return true;
        }

        $assets = is_array($item->assets) ? $item->assets : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            if (str_contains($type, 'ai_generated') || $type === 'demo_image') {
                return true;
            }
        }

        return false;
    }
}









