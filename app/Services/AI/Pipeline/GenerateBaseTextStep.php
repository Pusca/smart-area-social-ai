<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Services\AI\ContentAlignmentService;
use App\Services\AI\ContentAngleEngine;
use App\Services\AI\VideoScenePlanner;
use App\Services\GenerationAuditService;
use App\Services\GenerationMetricsService;
use App\Services\OpenAiService;
use App\Services\Overlays\ContentOverlayEngine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateBaseTextStep
{
    public function __construct(
        private readonly OpenAiService $openAi,
        private readonly ContentAlignmentService $contentAlignment,
        private readonly ContentAngleEngine $contentAngleEngine,
        private readonly VideoScenePlanner $videoScenePlanner,
        private readonly ContentOverlayEngine $contentOverlayEngine,
        private readonly GenerationAuditService $generationAudit,
        private readonly GenerationMetricsService $generationMetrics
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): GenerationPipelineState
    {
        $item = $state->item;
        $meta = $state->meta;
        $providerMatrix = (array) $state->get('provider_matrix', []);
        $tenantProfile = (array) $state->get('tenant_profile', []);
        $itemBrain = (array) $state->get('item_brain', []);
        $strategy = (array) $state->get('strategy', []);
        $memorySummary = (array) $state->get('memory_summary', []);
        $activeFeedbackRequest = (array) $state->get('active_feedback_request', []);
        $assetVariables = (array) $state->get('asset_variables', []);
        $assetIdentity = (array) $state->get('asset_identity', []);
        $briefSeed = (string) $state->get('brief_seed', '');
        $recentCaptions = (array) $state->get('recent_captions', []);
        $planTitles = (array) $state->get('plan_titles', []);
        $planCaptions = (array) $state->get('plan_captions', []);
        $contentStrategyPack = $this->contentAngleEngine->build([
            'platform' => (string) ($item->platform ?? 'instagram'),
            'format' => (string) ($item->format ?? 'post'),
            'goal' => (string) data_get($itemBrain, 'objective', data_get($meta, 'plan.goal', data_get($strategy, 'analysis_framework.primary_goal', 'Awareness'))),
            'audience' => (string) data_get($tenantProfile, 'target', data_get($strategy, 'brand_voice.target', '')),
            'industry' => (string) data_get($tenantProfile, 'industry', data_get($strategy, 'brand_voice.industry', '')),
            'topic' => (string) data_get($itemBrain, 'narrative_angle', data_get($itemBrain, 'angle', $item->title ?: $briefSeed)),
            'rubric' => (string) data_get($itemBrain, 'rubric', $item->rubric),
            'editorial_mode' => (string) data_get($itemBrain, 'editorial_mode', 'evergreen'),
            'trend_confidence' => data_get($itemBrain, 'trend_confidence'),
            'trend_opportunity' => (array) data_get($itemBrain, 'trend_opportunity', []),
            'tenant_profile' => $tenantProfile,
            'strategy' => $strategy,
            'item_brain' => $itemBrain,
            'plan' => (array) data_get($meta, 'plan', []),
            'manual_brief' => $briefSeed,
            'item' => [
                'platform' => (string) $item->platform,
                'format' => (string) $item->format,
                'title' => (string) ($item->title ?? ''),
            ],
        ]);
        $contentStrategyMeta = $this->contentAngleEngine->toMetaFragment($contentStrategyPack);
        $itemBrain = array_replace_recursive(
            $itemBrain,
            (array) data_get($contentStrategyMeta, 'item_brain', [])
        );
        $meta = array_replace_recursive($meta, $contentStrategyMeta, [
            'item_brain' => $itemBrain,
        ]);
        $initialOverlayPack = $this->contentOverlayEngine->build([
            'platform' => (string) ($item->platform ?? 'instagram'),
            'format' => (string) ($item->format ?? 'post'),
            'tenant_profile' => $tenantProfile,
            'strategy' => $strategy,
            'item_brain' => $itemBrain,
            'hook_meta' => (array) data_get($meta, 'hook_meta', data_get($itemBrain, 'hook_meta', [])),
            'overlay_settings' => (array) data_get($meta, 'overlay_settings', []),
            'caption' => $briefSeed,
            'item' => [
                'platform' => (string) $item->platform,
                'format' => (string) $item->format,
                'title' => (string) ($item->title ?? ''),
            ],
        ]);
        $meta = array_replace_recursive($meta, $this->contentOverlayEngine->toMetaFragment($initialOverlayPack));
        $itemBrain = array_replace_recursive(
            $itemBrain,
            (array) data_get($meta, 'item_brain', [])
        );
        $state->meta = $meta;
        $state->put('item_brain', $itemBrain);
        $item->ai_meta = $meta;
        $item->save();

        $textAttempt = $this->generationAudit->startAttempt($state->run, 'text_blueprint', [
            'type' => 'text',
            'stage' => 'text_blueprint',
            'provider_requested' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
            'provider_effective' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
            'model_requested' => (string) config('openai.text_model', 'gpt-4.1-mini'),
            'model_effective' => (string) config('openai.text_model', 'gpt-4.1-mini'),
            'input_summary' => [
                'format' => (string) $item->format,
                'platform' => (string) $item->platform,
                'brief_seed' => Str::limit($briefSeed, 220, ''),
                'feedback_active' => !empty($activeFeedbackRequest),
                'knowledge_examples' => count((array) data_get($meta, 'examples', [])),
                'negative_examples' => count((array) data_get($meta, 'negative_examples', [])),
            ],
        ]);

        try {
            $context = [
                'brand' => $tenantProfile,
                'plan' => data_get($meta, 'plan', []),
                'strategy' => $strategy,
                'strategy_blueprint' => [
                    'analysis_framework' => (array) data_get($strategy, 'analysis_framework', []),
                    'visual_system' => (array) data_get($strategy, 'visual_system', []),
                    'publishing_system' => (array) data_get($strategy, 'publishing_system', []),
                    'creative_direction' => (array) data_get($strategy, 'creative_direction', []),
                    'trend_intelligence' => (array) data_get($strategy, 'trend_intelligence', []),
                    'notes' => (string) data_get($strategy, 'strategy_notes', ''),
                ],
                'content_strategy_blueprint' => [
                    'content_strategy' => (array) data_get($meta, 'content_strategy', []),
                    'hook_meta' => (array) data_get($meta, 'hook_meta', data_get($itemBrain, 'hook_meta', [])),
                    'authority_signals' => (array) data_get($meta, 'authority_signals', data_get($itemBrain, 'authority_signals', [])),
                    'trust_signals' => (array) data_get($meta, 'trust_signals', data_get($itemBrain, 'trust_signals', [])),
                    'viral_angle' => (array) data_get($meta, 'viral_angle', data_get($itemBrain, 'viral_angle_meta', [])),
                    'content_structure_meta' => (array) data_get($meta, 'content_structure_meta', data_get($itemBrain, 'content_structure_meta', [])),
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
                'social_publication_context' => $job->buildSocialPublicationContext(
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
            $bestUsage = [];
            $bestResponseId = null;
            $generationAttemptsUsed = 0;
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

                $gen = $this->openAi->generateContent($iterContext);
                $generationAttemptsUsed = $attempt + 1;
                $caption = trim((string) ($gen['caption'] ?? ''));
                $score = $job->maxTextSimilarity($caption, $comparisonTexts);
                $alignmentReview = null;
                $alignmentScore = 1.0;
                $usage = is_array($gen['usage'] ?? null) ? (array) $gen['usage'] : [];
                $responseId = isset($gen['response_id']) ? (string) $gen['response_id'] : null;

                if ((bool) config('generation.alignment_enabled', true)) {
                    $alignmentReview = $this->contentAlignment->gradeTextDraft($iterContext, $gen, $providerMatrix);
                    $alignmentScore = max(0.0, min(1.0, (float) ($alignmentReview['overall_score'] ?? 0.0)));
                }

                $combinedScore = round(((1 - min(1.0, $score)) * 0.38) + ($alignmentScore * 0.62), 4);
                if ($combinedScore > $bestCombinedScore) {
                    $bestCombinedScore = $combinedScore;
                    $bestScore = $score;
                    $bestAlignmentScore = $alignmentScore;
                    $bestGen = $gen;
                    $bestAlignmentReview = $alignmentReview;
                    $bestUsage = $usage;
                    $bestResponseId = $responseId;
                }

                if ($score < 0.72 && !((bool) ($alignmentReview['should_retry'] ?? false))) {
                    $bestGen = $gen;
                    $bestScore = $score;
                    $bestAlignmentScore = $alignmentScore;
                    $bestAlignmentReview = $alignmentReview;
                    $bestUsage = $usage;
                    $bestResponseId = $responseId;
                    break;
                }

                $similarityFeedback = $score >= 0.72 ? [
                    'score' => $score,
                    'text' => $job->closestText($caption, $comparisonTexts),
                ] : null;
                $alignmentFeedback = is_array($alignmentReview) && !empty($alignmentReview['feedback'])
                    ? (array) $alignmentReview['feedback']
                    : null;
            }

            $gen = $bestGen ?? [];
            $textAlignmentReview = is_array($bestAlignmentReview) ? $bestAlignmentReview : null;
            $textAttemptMetrics = $this->generationMetrics->buildTextAttemptMetrics($item, [
                'provider_effective' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
                'model_effective' => (string) config('openai.text_model', 'gpt-4.1-mini'),
                'token_usage' => $bestUsage,
                'retry_index' => max(0, $generationAttemptsUsed - 1),
                'status' => 'succeeded',
            ]);

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

            $normalizedReelBlueprint = $job->normalizeReelBlueprint(
                blueprint: is_array($gen['reel_blueprint'] ?? null) ? $gen['reel_blueprint'] : [],
                item: $item,
                meta: $meta,
                assetVariables: $assetVariables,
                videoPrompt: $generatedVideoPrompt !== '' ? $generatedVideoPrompt : trim((string) ($gen['image_prompt'] ?? ''))
            );
            if (!empty($normalizedReelBlueprint)) {
                $nextMeta['reel_blueprint'] = $normalizedReelBlueprint;
            }
            $storyboardPack = $this->videoScenePlanner->build([
                'platform' => (string) ($item->platform ?? 'instagram'),
                'format' => (string) ($item->format ?? 'post'),
                'tenant_profile' => $tenantProfile,
                'strategy' => $strategy,
                'item_brain' => $itemBrain,
                'hook_meta' => (array) data_get($nextMeta, 'hook_meta', data_get($itemBrain, 'hook_meta', [])),
                'content_structure_meta' => (array) data_get($nextMeta, 'content_structure_meta', data_get($itemBrain, 'content_structure_meta', [])),
                'reel_blueprint' => $normalizedReelBlueprint,
                'asset_identity' => $assetIdentity,
                'asset_variables' => $assetVariables,
                'video_voiceover' => $generatedVoiceover !== '' ? $generatedVoiceover : (string) data_get($nextMeta, 'video_voiceover', ''),
                'video_prompt' => $generatedVideoPrompt !== '' ? $generatedVideoPrompt : (string) data_get($nextMeta, 'video_prompt', ''),
                'requested_total_seconds' => (int) data_get($nextMeta, 'requested_video_duration_seconds', data_get($nextMeta, 'video_duration_seconds_requested', 0)),
                'ai_cta' => (string) ($item->ai_cta ?? ''),
                'caption' => (string) ($item->ai_caption ?? ''),
                'item' => [
                    'platform' => (string) $item->platform,
                    'format' => (string) $item->format,
                    'title' => (string) ($item->title ?? ''),
                ],
            ]);
            $nextMeta = array_replace_recursive($nextMeta, $this->videoScenePlanner->toMetaFragment($storyboardPack));
            $overlayPack = $this->contentOverlayEngine->build([
                'platform' => (string) ($item->platform ?? 'instagram'),
                'format' => (string) ($item->format ?? 'post'),
                'tenant_profile' => $tenantProfile,
                'strategy' => $strategy,
                'item_brain' => $itemBrain,
                'hook_meta' => (array) data_get($nextMeta, 'hook_meta', data_get($itemBrain, 'hook_meta', [])),
                'overlay_settings' => (array) data_get($nextMeta, 'overlay_settings', []),
                'content_structure_meta' => (array) data_get($nextMeta, 'content_structure_meta', data_get($itemBrain, 'content_structure_meta', [])),
                'storyboard_meta' => (array) data_get($nextMeta, 'storyboard_meta', []),
                'caption' => (string) ($item->ai_caption ?? ''),
                'ai_cta' => (string) ($item->ai_cta ?? ''),
                'video_generation' => (array) data_get($nextMeta, 'video_generation', []),
                'video_duration_seconds_requested' => (int) data_get($nextMeta, 'requested_video_duration_seconds', 0),
                'item' => [
                    'platform' => (string) $item->platform,
                    'format' => (string) $item->format,
                    'title' => (string) ($item->title ?? ''),
                ],
            ]);
            $nextMeta = array_replace_recursive($nextMeta, $this->contentOverlayEngine->toMetaFragment($overlayPack));
            $itemBrain = array_replace_recursive($itemBrain, (array) data_get($nextMeta, 'item_brain', []));

            $item->ai_meta = $nextMeta;
            $item->save();

            $this->generationAudit->completeAttempt($textAttempt, [
                'status' => 'succeeded',
                'external_response_id' => $bestResponseId,
                'output_summary' => [
                    'caption_present' => trim((string) ($item->ai_caption ?? '')) !== '',
                    'hashtags_count' => count((array) ($item->ai_hashtags ?? [])),
                    'cta_present' => trim((string) ($item->ai_cta ?? '')) !== '',
                    'video_prompt_present' => trim((string) $generatedVideoPrompt) !== '',
                    'voiceover_present' => trim((string) $generatedVoiceover) !== '',
                    'reel_blueprint_present' => !empty($normalizedReelBlueprint),
                    'storyboard_present' => !empty($storyboardPack),
                    'storyboard_scene_count' => (int) data_get($storyboardPack, 'scene_count', 0),
                    'text_similarity_score' => round($bestScore, 4),
                    'text_alignment_score' => round($bestAlignmentScore, 4),
                ],
            ] + $textAttemptMetrics);
        } catch (Throwable $e) {
            if ($job->isQuotaOrRateLimitError($e) || $job->isTransientNetworkError($e)) {
                $reason = $job->isQuotaOrRateLimitError($e)
                    ? 'OpenAI quota/rate-limit'
                    : 'OpenAI rete/DNS non raggiungibile';

                $fallback = $job->fallbackText($item, $tenantProfile, $itemBrain);
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

                $this->generationAudit->completeAttempt($textAttempt, [
                    'status' => 'degraded',
                    'error_code' => 'text_fallback',
                    'error_message' => $reason,
                    'output_summary' => [
                        'fallback' => true,
                        'caption_present' => trim((string) ($item->ai_caption ?? '')) !== '',
                        'hashtags_count' => count((array) ($item->ai_hashtags ?? [])),
                        'cta_present' => trim((string) ($item->ai_cta ?? '')) !== '',
                    ],
                ] + $this->generationMetrics->buildTextAttemptMetrics($item, [
                    'provider_effective' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
                    'model_effective' => (string) config('openai.text_model', 'gpt-4.1-mini'),
                    'fallback_used' => true,
                    'retry_index' => 0,
                    'status' => 'degraded',
                    'failure_mode' => 'fallback_applied',
                    'error_message' => $reason,
                ]));
            } else {
                $item->ai_status = 'error';
                $item->ai_error = 'TEXT: ' . $e->getMessage();
                $item->save();

                Log::error('GenerateAiForContentItem text failed', [
                    'content_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);

                $this->generationAudit->failAttempt($textAttempt, $e, [
                    'provider_requested' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
                    'provider_effective' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
                    'model_requested' => (string) config('openai.text_model', 'gpt-4.1-mini'),
                    'model_effective' => (string) config('openai.text_model', 'gpt-4.1-mini'),
                ] + $this->generationMetrics->buildTextAttemptMetrics($item, [
                    'provider_effective' => (string) data_get($providerMatrix, 'text.provider', 'openai'),
                    'model_effective' => (string) config('openai.text_model', 'gpt-4.1-mini'),
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]));

                throw $e;
            }
        }

        $state->meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $state->put('item_brain', (array) data_get($state->meta, 'item_brain', []));

        return $state;
    }
}
