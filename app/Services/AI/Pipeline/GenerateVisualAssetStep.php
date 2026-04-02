<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Services\AI\ContentAlignmentService;
use App\Services\GenerationAuditService;
use App\Services\GenerationMetricsService;
use App\Services\GoogleVeoService;
use App\Services\KlingService;
use App\Services\NanoBananaService;
use App\Services\OpenAiService;
use App\Services\Overlays\ContentOverlayRenderer;
use App\Services\RunwayService;
use App\Services\SpeechSynthesisService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateVisualAssetStep
{
    public function __construct(
        private readonly OpenAiService $openAi,
        private readonly RunwayService $runway,
        private readonly KlingService $kling,
        private readonly GoogleVeoService $googleVeo,
        private readonly NanoBananaService $nanoBanana,
        private readonly ContentAlignmentService $contentAlignment,
        private readonly SpeechSynthesisService $speechSynthesis,
        private readonly ContentOverlayRenderer $contentOverlayRenderer,
        private readonly GenerationAuditService $generationAudit,
        private readonly GenerationMetricsService $generationMetrics
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): GenerationPipelineState
    {
        $item = $state->item;
        $tenantProfile = (array) $state->get('tenant_profile', []);
        $strategy = (array) $state->get('strategy', []);
        $activeFeedbackRequest = (array) $state->get('active_feedback_request', []);
        $assetVariables = (array) $state->get('asset_variables', []);
        $providerMatrix = (array) $state->get('provider_matrix', []);
        $prompt = (string) $state->get('visual.prompt', trim((string) ($item->ai_image_prompt ?? '')));
        $brandDecision = (array) $state->get('visual.brand_decision', []);
        $selectedBrandImage = $state->get('visual.selected_brand_image');
        $selectedBrandImagePaths = (array) $state->get('visual.selected_brand_image_paths', []);
        $selectedBrandImageAbs = $state->get('visual.selected_brand_image_abs');
        $selectedBrandImageAbsList = (array) $state->get('visual.selected_brand_image_abs_list', []);
        $logoRuntime = (array) $state->get('visual.logo_runtime', []);
        $logoSceneAbs = $state->get('visual.logo_scene_abs');
        $logoScenePath = $state->get('visual.logo_scene_path');
        $logoRequested = (bool) $state->get('visual.logo_requested', false);
        $logoMode = (string) $state->get('visual.logo_mode', 'scene');
        $embedLogoInScene = (bool) $state->get('visual.embed_logo_in_scene', false);

        $visualAttemptType = $job->isVideoFormat($item) ? 'video' : 'image';
        $visualAttemptProviderRequested = $visualAttemptType === 'video'
            ? (string) data_get($providerMatrix, 'video.provider', data_get($item->ai_meta, 'video_provider', ''))
            : (string) data_get($providerMatrix, 'image.provider', data_get($item->ai_meta, 'image_provider', ''));
        $visualRequestedSeconds = $visualAttemptType === 'video'
            ? (int) data_get($item->ai_meta, 'video_duration_seconds_requested', $job->targetVideoSecondsForFormat($item))
            : null;
        $visualRequestedModel = $visualAttemptType === 'video'
            ? $job->normalizeVideoModelForProvider(
                $visualAttemptProviderRequested,
                (string) data_get($item->ai_meta, 'video_model', '')
            )
            : null;
        $visualNormalizedSeconds = $visualAttemptType === 'video' && $visualRequestedSeconds !== null
            ? $job->normalizeVideoSecondsForProvider(
                $visualAttemptProviderRequested,
                $visualRequestedSeconds,
                (string) $visualRequestedModel
            )
            : null;

        $visualAttempt = $this->generationAudit->startAttempt($state->run, 'visual_asset', [
            'type' => $visualAttemptType,
            'stage' => 'visual_asset',
            'provider_requested' => $visualAttemptProviderRequested,
            'model_requested' => $visualRequestedModel,
            'provider_locked' => (bool) data_get($item->ai_meta, 'video_provider_lock', false),
            'requested_duration_seconds' => $visualRequestedSeconds,
            'normalized_duration_seconds' => $visualNormalizedSeconds,
            'input_summary' => [
                'kind' => $visualAttemptType,
                'format' => (string) $item->format,
                'platform' => (string) $item->platform,
                'strict_asset_mode' => $state->strictAssetMode,
                'requested_video_seconds' => $visualRequestedSeconds,
                'normalized_video_seconds' => $visualNormalizedSeconds,
                'asset_selection' => $job->buildAssetSelectionAuditSummary(
                    is_array($item->ai_meta) ? $item->ai_meta : [],
                    true
                ),
            ],
        ]);

        try {
            $recentImageHashes = $job->loadRecentImageHashes($item->tenant_id, $item->id, 24);
            $bytes = null;
            $finalHash = null;
            $imageSourceMode = 'text_to_image';
            $brandSourceUsed = null;
            $brandSourcesUsed = [];
            $imageSourceFallback = null;
            $publicDisk = Storage::disk('public');
            $lastImageAlignmentReview = null;

            if ($job->isVideoFormat($item)) {
                $videoResult = $job->generateVideoAsset(
                    openAi: $this->openAi,
                    nanoBanana: $this->nanoBanana,
                    runway: $this->runway,
                    kling: $this->kling,
                    googleVeo: $this->googleVeo,
                    item: $item,
                    prompt: $prompt,
                    selectedBrandImageAbs: is_string($selectedBrandImageAbs) ? $selectedBrandImageAbs : null,
                    selectedBrandImageAbsList: $selectedBrandImageAbsList,
                    selectedBrandImagePath: is_string($selectedBrandImage) ? $selectedBrandImage : null,
                    selectedBrandImagePaths: $selectedBrandImagePaths,
                    logoRuntime: $logoRuntime,
                    activeFeedbackRequest: $activeFeedbackRequest,
                    brandDecision: $brandDecision
                );

                $videoPath = trim((string) ($videoResult['video_path'] ?? ''));
                $thumbPath = trim((string) ($videoResult['thumbnail_path'] ?? ''));

                if ($videoPath !== '') {
                    $skipPlaybackPostprocess = (bool) ($videoResult['skip_playback_postprocess'] ?? false);
                    if ($skipPlaybackPostprocess) {
                        $videoPlaybackPostprocess = [
                            'applied' => false,
                            'reason' => 'already_playback_ready',
                            'provider' => (string) ($videoResult['provider'] ?? data_get($item->ai_meta, 'video_provider', '')),
                            'input_video_path' => $videoPath,
                            'video_path' => $videoPath,
                            'error' => null,
                        ];
                    } else {
                        $videoPlaybackPostprocess = $job->postProcessGeneratedVideoForPlayback(
                            item: $item,
                            videoPath: $videoPath,
                            provider: (string) ($videoResult['provider'] ?? data_get($item->ai_meta, 'video_provider', ''))
                        );
                        if (!empty($videoPlaybackPostprocess['video_path']) && is_string($videoPlaybackPostprocess['video_path'])) {
                            $videoPath = (string) $videoPlaybackPostprocess['video_path'];
                        }
                    }

                    $audioAttach = $job->maybeAttachAudioTrackToVideo(
                        item: $item,
                        videoPath: $videoPath,
                        speechSynthesis: $this->speechSynthesis
                    );
                    $audioAttach['input_video_path'] = $videoPlaybackPostprocess['input_video_path'] ?? $videoPath;
                    $audioAttach['postprocess'] = $videoPlaybackPostprocess;
                    if ((bool) ($audioAttach['applied'] ?? false) && !empty($audioAttach['video_path'])) {
                        $videoPath = (string) $audioAttach['video_path'];
                    }
                    $job->logVideoAudioOutcome($item, $audioAttach);

                    $gridPreviewPath = $job->createLocalImagePlaceholder($item, $tenantProfile);
                    if (is_string($gridPreviewPath) && trim($gridPreviewPath) !== '') {
                        $item->ai_image_path = $gridPreviewPath;
                    } elseif ($thumbPath !== '') {
                        $item->ai_image_path = $thumbPath;
                    }

                    $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
                    $storyboardMeta = (array) data_get($metaNow, 'storyboard_meta', []);
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
                        'logo_source_path' => is_string($logoScenePath) ? $logoScenePath : '',
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
                        'extended' => $videoResult['extended'] ?? null,
                        'segment_count' => (int) ($videoResult['segment_count'] ?? count((array) ($videoResult['segments'] ?? []))),
                        'target_total_seconds' => (int) ($videoResult['target_total_seconds'] ?? 0),
                        'segments' => (array) ($videoResult['segments'] ?? []),
                        'storyboard_summary' => $job->compactStoryboardSummary($storyboardMeta),
                        'extended_fallback' => $videoResult['extended_fallback'] ?? null,
                        'audio' => $audioAttach,
                        'playback_postprocess' => $videoPlaybackPostprocess,
                        'fallback' => $imageSourceFallback,
                        'provider_fallback' => $videoResult['provider_fallback'] ?? null,
                        'generated_at' => now()->toDateTimeString(),
                    ];
                    $metaNow['video_provider_requested'] = \App\Support\VideoProviderResolver::normalize((string) data_get($metaNow, 'video_provider', ''));
                    $metaNow['video_provider_last_used'] = (string) ($videoResult['provider'] ?? data_get($metaNow, 'video_provider', 'openai'));
                    $item->ai_meta = $metaNow;

                    $assets = array_values(array_filter(
                        is_array($item->assets) ? $item->assets : [],
                        fn ($asset) => !is_array($asset)
                            || !in_array(strtolower(trim((string) ($asset['type'] ?? ''))), ['ai_generated_video', 'ai_generated_audio', 'ai_generated_thumbnail'], true)
                    ));
                    foreach ($selectedBrandImagePaths as $brandPath) {
                        $assets[] = ['type' => 'brand_source', 'path' => $brandPath];
                    }
                    if ($logoRequested && is_string($logoScenePath) && $logoScenePath !== '') {
                        $assets[] = ['type' => 'brand_logo', 'path' => $logoScenePath];
                    }
                    $assets[] = ['type' => 'ai_generated_video', 'path' => $videoPath];
                    if (!empty($audioAttach['audio_path']) && is_string($audioAttach['audio_path'])) {
                        $assets[] = ['type' => 'ai_generated_audio', 'path' => (string) $audioAttach['audio_path']];
                    }
                    if ($thumbPath !== '') {
                        $assets[] = ['type' => 'ai_generated_thumbnail', 'path' => $thumbPath];
                    }
                    $item->assets = $job->uniqueAssets($assets);
                    $item->save();
                }
            } else {
                for ($imgAttempt = 0; $imgAttempt < 2; $imgAttempt++) {
                    $attemptPrompt = $prompt;
                    if ($imgAttempt > 0) {
                        $attemptPrompt .= ' Crea una composizione visibilmente diversa dai post brand precedenti (nuovo layout, inquadratura e gerarchia visiva).';
                    }
                    $attemptPrompt .= ' Se compaiono scritte visibili nell immagine, devono essere in italiano naturale e corretto.';
                    $attemptPrompt .= ' ' . $job->instagramVisualOutputInstruction($item);
                    $attemptPrompt .= ' ' . $job->multiReferenceBlendInstruction($selectedBrandImagePaths);
                    $attemptPrompt .= ' ' . $job->locationEnvelopePreservationInstruction($assetVariables, $selectedBrandImagePaths);

                    if (!empty($selectedBrandImageAbsList) || (is_string($logoSceneAbs) && $logoSceneAbs !== '')) {
                        try {
                            $editPaths = [];
                            foreach (array_slice($selectedBrandImageAbsList, 0, 4) as $referenceAbs) {
                                if (is_string($referenceAbs) && $referenceAbs !== '') {
                                    $editPaths[] = $referenceAbs;
                                }
                            }
                            if ($embedLogoInScene && is_string($logoSceneAbs) && $logoSceneAbs !== '' && count($editPaths) < 4) {
                                $editPaths[] = $logoSceneAbs;
                            }
                            if (empty($editPaths) && is_string($logoSceneAbs) && $logoSceneAbs !== '') {
                                $editPaths[] = $logoSceneAbs;
                            }

                            if ($logoRequested && is_string($logoSceneAbs) && $logoSceneAbs !== '') {
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

                            $img = $job->generateImageEditWithProvider(
                                provider: $job->resolveImageProvider((array) ($item->ai_meta ?? [])),
                                prompt: $attemptPrompt,
                                editPaths: $editPaths,
                                openAi: $this->openAi,
                                nanoBanana: $this->nanoBanana
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
                            $img = $job->generateImageTextWithProvider(
                                provider: $job->resolveImageProvider((array) ($item->ai_meta ?? [])),
                                prompt: $attemptPrompt,
                                openAi: $this->openAi,
                                nanoBanana: $this->nanoBanana
                            );
                            $imageSourceMode = 'text_to_image';
                            $brandSourcesUsed = [];
                            $brandSourceUsed = null;
                            $metaFallback = is_array($item->ai_meta) ? $item->ai_meta : [];
                            $metaFallback['image_edit_error'] = Str::limit($editError->getMessage(), 240, '');
                            $metaFallback['image_edit_error_at'] = now()->toDateTimeString();
                            $metaFallback['image_provider'] = $job->resolveImageProvider($metaFallback);
                            $item->ai_meta = $metaFallback;
                            $item->save();
                        }
                    } else {
                        $img = $job->generateImageTextWithProvider(
                            provider: $job->resolveImageProvider((array) ($item->ai_meta ?? [])),
                            prompt: $attemptPrompt,
                            openAi: $this->openAi,
                            nanoBanana: $this->nanoBanana
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
                            $currentImageAlignmentReview = $this->contentAlignment->validateGeneratedImageCandidate(
                                (string) $state->get('brief_seed', ''),
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

                    $candidateHash = $job->computeImageHashFromBytes($candidateBytes);
                    if ($candidateHash === null) {
                        $bytes = $candidateBytes;
                        $finalHash = null;
                        $lastImageAlignmentReview = $currentImageAlignmentReview;
                        break;
                    }

                    $similarity = $job->maxImageHashSimilarity($candidateHash, $recentImageHashes);
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
                    $resolvedImageProvider = $job->resolveImageProvider($metaNow);
                    $metaNow['image_provider'] = $resolvedImageProvider;
                    $metaNow['image_generation'] = [
                        'provider' => $resolvedImageProvider,
                        'source' => $imageSourceMode,
                        'brand_source_path' => $brandSourceUsed,
                        'brand_source_paths' => $brandSourcesUsed,
                        'logo_requested' => $logoRequested,
                        'logo_in_scene' => in_array($imageSourceMode, ['brand_image_edit', 'brand_multi_image_edit', 'logo_guided_edit'], true) ? $embedLogoInScene : false,
                        'logo_source_path' => is_string($logoScenePath) ? $logoScenePath : '',
                        'logo_mode' => $logoMode,
                        'logo_variant' => (string) ($logoRuntime['variant'] ?? 'auto'),
                        'logo_selection_reason' => (string) ($logoRuntime['reason'] ?? ''),
                        'brand_selection' => $brandDecision,
                        'fallback' => $imageSourceFallback,
                        'alignment_review' => $lastImageAlignmentReview,
                        'image_hash' => $finalHash,
                        'generated_at' => now()->toDateTimeString(),
                    ];
                    if ($logoRequested && is_string($logoScenePath) && $logoScenePath !== '' && ($logoMode === 'background' || $imageSourceMode === 'text_to_image')) {
                        $overlayMeta = $metaNow;
                        $overlayMeta['logo_runtime'] = [
                            'force' => true,
                            'path' => $logoScenePath,
                            'mode' => $logoMode === 'background' ? 'background' : 'corner',
                        ];
                        $overlay = $job->applyBrandLogoOverlay($item, $strategy, $overlayMeta);
                        $metaNow['image_generation']['logo_overlay'] = $overlay;
                    }
                    $item->ai_meta = $metaNow;

                    $assets = is_array($item->assets) ? $item->assets : [];
                    foreach ($brandSourcesUsed as $brandPath) {
                        $assets[] = ['type' => 'brand_source', 'path' => $brandPath];
                    }
                    if ($logoRequested && is_string($logoScenePath) && $logoScenePath !== '') {
                        $assets[] = ['type' => 'brand_logo', 'path' => $logoScenePath];
                    }
                    $assets[] = ['type' => 'ai_generated', 'path' => $filename];
                    $item->assets = $job->uniqueAssets($assets);
                    $item->save();
                }
            }

            if ($job->hasGeneratedVisualOutput($item)) {
                $this->contentOverlayRenderer->apply($item);
            }

            $visualOutputPersisted = $job->hasGeneratedVisualOutput($item);
            $visualAttemptAuditAttributes = $job->buildVisualAttemptAuditAttributes($item);
            $visualAttemptMetrics = $this->generationMetrics->buildVisualAttemptMetrics($item, [
                'provider_effective' => (string) data_get($visualAttemptAuditAttributes, 'provider_effective', ''),
                'model_effective' => (string) data_get($visualAttemptAuditAttributes, 'model_effective', ''),
                'status' => $visualOutputPersisted ? 'succeeded' : 'failed',
            ]);
            $this->generationAudit->completeAttempt($visualAttempt, array_merge(
                $visualAttemptAuditAttributes,
                [
                    'status' => $visualOutputPersisted ? 'succeeded' : 'failed',
                    'output_summary' => $job->buildVisualAttemptOutputSummary($item),
                    'error_code' => $visualOutputPersisted ? null : 'missing_visual_output',
                    'error_message' => $visualOutputPersisted ? null : 'No visual output persisted by the generation step.',
                ],
                $visualAttemptMetrics
            ));
        } catch (Throwable $e) {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $isVideoFailure = $job->isVideoFormat($item);
            $errorKey = $isVideoFailure ? 'video_error' : 'image_error';
            $errorAtKey = $errorKey . '_at';
            $meta[$errorKey] = $e->getMessage();
            $meta[$errorAtKey] = now()->toDateTimeString();

            if ($isVideoFailure) {
                $meta['video_provider_requested'] = $job->resolveVideoProvider($meta);
                $item->ai_image_path = null;
            } else {
                $meta['image_provider'] = $job->resolveImageProvider($meta);
                $isNet = $job->isTransientNetworkError($e);
                $isBilling = $job->isImageBillingLimitError($e);

                if ($isNet || $isBilling) {
                    $placeholderPath = $job->createLocalImagePlaceholder($item, (array) $state->get('tenant_profile', []));
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

            $visualOutputPersisted = $job->hasGeneratedVisualOutput($item);
            $visualAttemptAuditAttributes = $job->buildVisualAttemptAuditAttributes($item);
            $visualAttemptMetrics = $this->generationMetrics->buildVisualAttemptMetrics($item, [
                'provider_effective' => (string) data_get($visualAttemptAuditAttributes, 'provider_effective', ''),
                'model_effective' => (string) data_get($visualAttemptAuditAttributes, 'model_effective', ''),
                'status' => $visualOutputPersisted ? 'degraded' : 'failed',
                'error_message' => $e->getMessage(),
            ]);
            if ($visualOutputPersisted) {
                $this->generationAudit->completeAttempt($visualAttempt, array_merge(
                    $visualAttemptAuditAttributes,
                    [
                        'status' => 'degraded',
                        'error_code' => 'visual_fallback_applied',
                        'error_message' => $e->getMessage(),
                        'output_summary' => $job->buildVisualAttemptOutputSummary($item),
                    ],
                    $visualAttemptMetrics
                ));
            } else {
                $this->generationAudit->failAttempt($visualAttempt, $e, array_merge(
                    $visualAttemptAuditAttributes,
                    [
                        'output_summary' => $job->buildVisualAttemptOutputSummary($item),
                    ],
                    $visualAttemptMetrics
                ));
            }

            Log::warning('GenerateAiForContentItem visual generation failed', [
                'content_item_id' => $item->id,
                'mode' => $isVideoFailure ? 'video' : 'image',
                'error' => $e->getMessage(),
            ]);
        }

        $state->meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        return $state;
    }
}

