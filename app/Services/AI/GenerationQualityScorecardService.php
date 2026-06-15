<?php

namespace App\Services\AI;

use App\Models\ContentItem;
use App\Models\GenerationRun;
use App\Support\ImagePromptRealismGuard;
use Illuminate\Support\Str;

class GenerationQualityScorecardService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForContentItem(ContentItem $item, ?GenerationRun $run = null): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $warnings = [];
        $blockingReasons = [];
        $scoreSources = [];

        [$brandVoiceScore, $brandVoiceSource, $brandVoiceWarnings] = $this->brandVoiceScore($item, $meta);
        [$captionQualityScore, $captionQualitySource, $captionWarnings] = $this->captionQualityScore($item, $meta);
        [$ctaComplianceScore, $ctaSource, $ctaWarnings] = $this->ctaComplianceScore($item, $meta);
        [$referenceMatchScore, $referenceSource, $referenceWarnings, $referenceBlocks, $referencesExpected] = $this->referenceMatchScore($meta);
        [$visualIdentityScore, $visualIdentitySource, $visualWarnings] = $this->visualIdentityScore($item, $meta, $referenceMatchScore, $referencesExpected);
        [$realismScore, $realismSource, $realismWarnings] = $this->realismScore($item, $meta, $run);
        $overlayReview = $this->overlayReview($meta);
        $storyboardReview = $this->storyboardReview($item, $meta);
        [$professionalismScore, $professionalismSource, $professionalismWarnings] = $this->professionalismScore($item, $meta);
        [$trendRelevanceScore, $trendRelevanceSource, $trendRelevanceWarnings] = $this->trendRelevanceScore($item, $meta);
        [$trendBrandFitScore, $trendBrandFitSource, $trendBrandFitWarnings] = $this->trendBrandFitScore($item, $meta);
        [$hookStrengthScore, $hookStrengthSource, $hookStrengthWarnings] = $this->hookStrengthScore($item, $meta, $storyboardReview);
        [$firstSecondsStrengthScore, $firstSecondsStrengthSource, $firstSecondsWarnings] = $this->firstSecondsStrengthScore($item, $meta, $storyboardReview);
        [$overlayReadabilityScore, $overlayReadabilitySource, $overlayReadabilityWarnings] = $this->overlayReadabilityScore($meta, $overlayReview);
        [$mobileLegibilityScore, $mobileLegibilitySource, $mobileLegibilityWarnings] = $this->mobileLegibilityScore($item, $meta, $overlayReview, $storyboardReview);
        [$viralReadinessScore, $viralReadinessSource, $viralReadinessWarnings] = $this->viralReadinessScore($meta, [
            'hook_strength_score' => $hookStrengthScore,
            'first_seconds_strength_score' => $firstSecondsStrengthScore,
            'trend_relevance_score' => $trendRelevanceScore,
            'trend_brand_fit_score' => $trendBrandFitScore,
            'overlay_readability_score' => $overlayReadabilityScore,
            'mobile_legibility_score' => $mobileLegibilityScore,
            'professionalism_score' => $professionalismScore,
            'asset_identity_confidence_score' => is_numeric(data_get($meta, 'asset_scoring.identity_confidence'))
                ? $this->normalizeScore((float) data_get($meta, 'asset_scoring.identity_confidence'))
                : null,
        ]);

        $scoreSources['brand_voice_score'] = $brandVoiceSource;
        $scoreSources['caption_quality_score'] = $captionQualitySource;
        $scoreSources['cta_compliance_score'] = $ctaSource;
        $scoreSources['reference_match_score'] = $referenceSource;
        $scoreSources['visual_identity_score'] = $visualIdentitySource;
        $scoreSources['realism_score'] = $realismSource;
        $scoreSources['professionalism_score'] = $professionalismSource;
        $scoreSources['trend_relevance_score'] = $trendRelevanceSource;
        $scoreSources['trend_brand_fit_score'] = $trendBrandFitSource;
        $scoreSources['hook_strength_score'] = $hookStrengthSource;
        $scoreSources['first_seconds_strength_score'] = $firstSecondsStrengthSource;
        $scoreSources['overlay_readability_score'] = $overlayReadabilitySource;
        $scoreSources['mobile_legibility_score'] = $mobileLegibilitySource;
        $scoreSources['viral_readiness_score'] = $viralReadinessSource;
        $scoreSources['creative_direction_review'] = $this->creativeDirectionReviewSource($meta);
        $scoreSources['trend_intelligence_review'] = $this->trendIntelligenceReviewSource($meta);
        $scoreSources['content_strategy_review'] = $this->contentStrategyReviewSource($meta);
        $scoreSources['overlay_review'] = $this->overlayReviewSource($meta);
        $scoreSources['storyboard_review'] = $this->storyboardReviewSource($meta);

        $warnings = array_merge(
            $warnings,
            $brandVoiceWarnings,
            $captionWarnings,
            $ctaWarnings,
            $referenceWarnings,
            $visualWarnings,
            $realismWarnings,
            $professionalismWarnings,
            $trendRelevanceWarnings,
            $trendBrandFitWarnings,
            $hookStrengthWarnings,
            $firstSecondsWarnings,
            $overlayReadabilityWarnings,
            $mobileLegibilityWarnings,
            $viralReadinessWarnings
        );
        $warnings = array_merge(
            $warnings,
            $this->creativeDirectionWarnings($meta),
            $this->trendIntelligenceWarnings($meta),
            $this->contentStrategyWarnings($item, $meta),
            $this->overlayWarnings($item, $meta, $overlayReview),
            $this->storyboardWarnings($item, $meta, $storyboardReview),
            $this->advancedScoreWarnings(
                item: $item,
                meta: $meta,
                professionalismScore: $professionalismScore,
                trendRelevanceScore: $trendRelevanceScore,
                trendBrandFitScore: $trendBrandFitScore,
                hookStrengthScore: $hookStrengthScore,
                firstSecondsStrengthScore: $firstSecondsStrengthScore,
                overlayReadabilityScore: $overlayReadabilityScore,
                mobileLegibilityScore: $mobileLegibilityScore,
                viralReadinessScore: $viralReadinessScore
            )
        );
        $blockingReasons = array_merge(
            $blockingReasons,
            $referenceBlocks,
            $this->trendIntelligenceBlockingReasons($meta),
            $this->advancedScoreBlockingReasons(
                item: $item,
                meta: $meta,
                professionalismScore: $professionalismScore,
                trendRelevanceScore: $trendRelevanceScore,
                trendBrandFitScore: $trendBrandFitScore,
                hookStrengthScore: $hookStrengthScore,
                firstSecondsStrengthScore: $firstSecondsStrengthScore,
                overlayReadabilityScore: $overlayReadabilityScore,
                mobileLegibilityScore: $mobileLegibilityScore
            )
        );

        $textReview = is_array(data_get($meta, 'text_alignment_review')) ? (array) data_get($meta, 'text_alignment_review') : [];
        $hardRuleViolations = array_values(array_filter(array_map(
            'strval',
            (array) data_get($textReview, 'heuristic.hard_rule_violations', [])
        )));

        if (!empty($hardRuleViolations)) {
            $blockingReasons[] = 'Il contenuto viola regole dure del tenant: ' . implode('; ', array_slice($hardRuleViolations, 0, 3));
        }

        if (trim((string) ($item->ai_caption ?? '')) === '') {
            $blockingReasons[] = 'La caption finale manca.';
        }

        if (!$this->hasVisualOutput($item, $meta)) {
            $blockingReasons[] = 'Manca un asset visuale finale utilizzabile.';
        }

        if ((string) ($item->ai_status ?? '') !== 'done') {
            $blockingReasons[] = 'La generazione non e completata correttamente.';
        }

        $fallbackUsed = $this->boolFromRunOrMeta($run, 'fallback_used', $meta, [
            'text_fallback',
            'image_generation.fallback',
            'video_generation.provider_fallback',
            'video_generation.fallback',
        ]);
        $downgradeUsed = $this->boolFromRunOrMeta($run, 'downgrade_used', $meta, [
            'video_generation.extended_fallback',
            'image_fallback',
        ]);

        if ($fallbackUsed) {
            $warnings[] = 'Durante la generazione e stato applicato almeno un fallback.';
        }
        if ($downgradeUsed) {
            $warnings[] = 'Durante la generazione e stato applicato almeno un downgrade.';
        }

        $manualThreshold = (float) config('ai_quality.status_thresholds.manual_review_score', 0.55);
        $warningThreshold = (float) config('ai_quality.status_thresholds.warning_score', 0.72);
        $blockedReferenceThreshold = (float) config('ai_quality.status_thresholds.blocked_reference_score', 0.45);
        $blockedCaptionThreshold = (float) config('ai_quality.status_thresholds.blocked_caption_score', 0.35);

        if ($referenceMatchScore !== null && $referencesExpected && $referenceMatchScore < $blockedReferenceThreshold) {
            $blockingReasons[] = 'I riferimenti reali non risultano sufficientemente rispettati.';
        }

        if ($captionQualityScore !== null && $captionQualityScore < $blockedCaptionThreshold) {
            $blockingReasons[] = 'La qualita della caption e troppo bassa per la pubblicazione diretta.';
        }

        $nonNullScores = array_values(array_filter([
            $brandVoiceScore,
            $visualIdentityScore,
            $ctaComplianceScore,
            $referenceMatchScore,
            $realismScore,
            $captionQualityScore,
            $professionalismScore,
            $hookStrengthScore,
            $this->isVideoFormat((string) $item->format) ? $firstSecondsStrengthScore : null,
        ], fn ($value) => $value !== null));

        $status = 'pass';
        if (!empty($blockingReasons)) {
            $status = 'blocked';
        } else {
            $requiresManualReview = $fallbackUsed || $downgradeUsed || ($referencesExpected && $referenceMatchScore === null);
            foreach ($nonNullScores as $score) {
                if ((float) $score < $manualThreshold) {
                    $requiresManualReview = true;
                    break;
                }
            }

            if ($requiresManualReview) {
                $status = 'manual_review_required';
            } else {
                $hasWarnings = !empty($warnings);
                if (!$hasWarnings) {
                    foreach ($nonNullScores as $score) {
                        if ((float) $score < $warningThreshold) {
                            $hasWarnings = true;
                            break;
                        }
                    }
                }

                if ($hasWarnings) {
                    $status = 'pass_with_warnings';
                }
            }
        }

        return [
            'version' => (string) config('ai_quality.version', 'quality_scorecard_v1'),
            'calculated_at' => now()->toDateTimeString(),
            'brand_voice_score' => $brandVoiceScore,
            'visual_identity_score' => $visualIdentityScore,
            'cta_compliance_score' => $ctaComplianceScore,
            'reference_match_score' => $referenceMatchScore,
            'realism_score' => $realismScore,
            'caption_quality_score' => $captionQualityScore,
            'professionalism_score' => $professionalismScore,
            'trend_relevance_score' => $trendRelevanceScore,
            'trend_brand_fit_score' => $trendBrandFitScore,
            'hook_strength_score' => $hookStrengthScore,
            'first_seconds_strength_score' => $firstSecondsStrengthScore,
            'overlay_readability_score' => $overlayReadabilityScore,
            'mobile_legibility_score' => $mobileLegibilityScore,
            'viral_readiness_score' => $viralReadinessScore,
            'publish_readiness_status' => $status,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'blocking_reasons' => array_values(array_unique(array_filter($blockingReasons))),
            'score_sources' => $scoreSources,
            'creative_direction_review' => [
                'overlay_requested' => trim((string) data_get($meta, 'item_brain.overlay_brief', '')) !== '',
                'trend_requested' => trim((string) data_get($meta, 'item_brain.trend_bridge', '')) !== '',
                'continuity_requested' => trim((string) data_get($meta, 'item_brain.continuity_brief', '')) !== '',
                'strategy_present' => !empty((array) data_get($meta, 'strategy.creative_direction', [])),
            ],
            'trend_intelligence_review' => [
                'trend_requested' => trim((string) data_get($meta, 'item_brain.trend_bridge', '')) !== '' || !empty((array) data_get($meta, 'item_brain.trend_opportunity', [])),
                'opportunity_present' => !empty((array) data_get($meta, 'item_brain.trend_opportunity', [])),
                'strategy_present' => !empty((array) data_get($meta, 'strategy.trend_intelligence', [])),
                'usage_mode' => (string) data_get($meta, 'item_brain.trend_usage_mode', ''),
                'confidence' => data_get($meta, 'item_brain.trend_confidence'),
                'basis_present' => !empty((array) data_get($meta, 'item_brain.trend_basis', [])),
                'guardrails_count' => count((array) data_get($meta, 'item_brain.professionality_guardrails', [])),
                'editorial_mode' => (string) data_get($meta, 'item_brain.editorial_mode', ''),
                'expected_engagement_goal' => (string) data_get($meta, 'item_brain.expected_engagement_goal', ''),
                'risk_flags' => array_values(array_filter(array_map('strval', (array) data_get($meta, 'item_brain.trend_opportunity.risk_flags', [])))),
                'brand_fit_score' => data_get($meta, 'item_brain.trend_opportunity.brand_fit_score'),
                'execution_feasibility_score' => data_get($meta, 'item_brain.trend_opportunity.execution_feasibility_score'),
            ],
            'content_strategy_review' => [
                'strategy_present' => !empty((array) data_get($meta, 'content_strategy', [])) || !empty((array) data_get($meta, 'item_brain.hook_meta', [])),
                'strategy_type' => (string) $this->contentStrategyValue($meta, 'content_strategy_type', data_get($meta, 'content_strategy.strategy_type', '')),
                'hook_present' => trim((string) $this->contentStrategyValue($meta, 'hook_meta.main_hook', '')) !== '',
                'alternative_hook_present' => trim((string) $this->contentStrategyValue($meta, 'hook_meta.alternative_hook', '')) !== '',
                'authority_signals_count' => count((array) $this->contentStrategyValue($meta, 'authority_signals', [])),
                'trust_signals_count' => count((array) $this->contentStrategyValue($meta, 'trust_signals', [])),
                'cta_mode' => (string) $this->contentStrategyValue($meta, 'hook_meta.cta_mode', ''),
                'opening_structure_present' => trim((string) $this->contentStrategyValue($meta, 'hook_meta.platform_specific_opening_structure', '')) !== '',
                'video_structure_present' => !empty((array) $this->contentStrategyValue($meta, 'content_structure_meta.video_segments', [])),
                'root_persisted' => $this->hasContentStrategyRootPersistence($meta),
            ],
            'overlay_review' => $overlayReview,
            'storyboard_review' => $storyboardReview,
            'professionalism_review' => [
                'guardrails_count' => count((array) data_get($meta, 'item_brain.professionality_guardrails', [])),
                'hard_rule_violations_count' => count((array) data_get($meta, 'text_alignment_review.heuristic.hard_rule_violations', [])),
                'aggressive_cta_detected' => $this->containsAggressiveLanguage(trim((string) ($item->ai_cta ?? ''))),
            ],
            'trend_score_review' => [
                'trend_expected' => $this->trendExpected($meta),
                'selection_trend_relevance' => (string) data_get($meta, 'content_strategy.selection_context.trend_relevance', data_get($meta, 'viral_angle.trend_relevance', '')),
                'trend_confidence' => data_get($meta, 'item_brain.trend_confidence'),
                'trend_brand_fit_source' => $trendBrandFitSource['source'] ?? 'not_available',
            ],
            'hook_review' => [
                'main_hook' => (string) $this->contentStrategyValue($meta, 'hook_meta.main_hook', ''),
                'opening_structure_present' => trim((string) $this->contentStrategyValue($meta, 'hook_meta.platform_specific_opening_structure', '')) !== '',
                'first_scene_present' => (bool) data_get($storyboardReview, 'hook_scene_present', false),
            ],
            'viral_readiness_review' => [
                'components_used' => (array) ($viralReadinessSource['components_used'] ?? []),
                'trend_expected' => $this->trendExpected($meta),
                'overlay_enabled' => (bool) data_get($overlayReview, 'enabled', false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scorecard
     */
    public function storeOnContentItem(ContentItem $item, array $scorecard, ?GenerationRun $run = null): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['quality_scorecard'] = $scorecard;
        $meta['generation_audit'] = array_merge(
            (array) data_get($meta, 'generation_audit', []),
            [
                'quality_scorecard_run_id' => $run?->id,
                'quality_scorecard_status' => (string) ($scorecard['publish_readiness_status'] ?? ''),
                'quality_scorecard_calculated_at' => (string) ($scorecard['calculated_at'] ?? ''),
            ]
        );
        $item->ai_meta = $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function brandVoiceScore(ContentItem $item, array $meta): array
    {
        $review = is_array(data_get($meta, 'text_alignment_review')) ? (array) data_get($meta, 'text_alignment_review') : [];
        $brandAlignment = data_get($review, 'llm.brand_alignment_score');
        if (is_numeric($brandAlignment)) {
            return [$this->normalizeScore((float) $brandAlignment), [
                'source' => 'text_alignment_review.llm.brand_alignment_score',
                'mode' => 'validated',
            ], []];
        }

        $overall = data_get($review, 'overall_score');
        if (is_numeric($overall)) {
            return [$this->normalizeScore((float) $overall), [
                'source' => 'text_alignment_review.overall_score',
                'mode' => 'validated',
            ], []];
        }

        if (trim((string) ($item->ai_caption ?? '')) !== '') {
            return [0.6, [
                'source' => 'caption_presence_and_tone_heuristic',
                'mode' => 'heuristic',
            ], ['Brand voice score calcolato senza review LLM finale del copy.']];
        }

        return [null, [
            'source' => 'not_available',
            'mode' => 'missing',
        ], ['Brand voice score non disponibile: manca una review testuale strutturata.']];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function captionQualityScore(ContentItem $item, array $meta): array
    {
        $review = is_array(data_get($meta, 'text_alignment_review')) ? (array) data_get($meta, 'text_alignment_review') : [];
        $overall = data_get($review, 'overall_score');
        if (is_numeric($overall)) {
            $score = (float) $overall;
            $briefAlignment = data_get($review, 'llm.brief_alignment_score');
            if (is_numeric($briefAlignment)) {
                $score = ($score * 0.65) + (((float) $briefAlignment) * 0.35);
            }

            $issues = count((array) data_get($review, 'llm.issues', []));
            if ($issues >= 3) {
                $score -= 0.05;
            }

            return [$this->normalizeScore($score), [
                'source' => 'text_alignment_review.overall_score',
                'mode' => 'validated',
            ], []];
        }

        $caption = trim((string) ($item->ai_caption ?? ''));
        if ($caption === '') {
            return [0.0, [
                'source' => 'caption_missing',
                'mode' => 'heuristic',
            ], ['Caption quality score basso: manca la caption finale.']];
        }

        $length = mb_strlen($caption, 'UTF-8');
        $hasCta = trim((string) ($item->ai_cta ?? '')) !== '';
        $score = $length >= 120 && $length <= 1600 ? 0.72 : 0.58;
        if ($hasCta) {
            $score += 0.05;
        }

        return [$this->normalizeScore($score), [
            'source' => 'caption_length_heuristic',
            'mode' => 'heuristic',
        ], ['Caption quality score calcolato senza review LLM finale del copy.']];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function ctaComplianceScore(ContentItem $item, array $meta): array
    {
        $ctaScore = data_get($meta, 'text_alignment_review.heuristic.cta_score');
        if (is_numeric($ctaScore)) {
            return [$this->normalizeScore((float) $ctaScore), [
                'source' => 'text_alignment_review.heuristic.cta_score',
                'mode' => 'validated',
            ], []];
        }

        $actualCta = Str::lower(trim((string) ($item->ai_cta ?? '')));
        $expectedCta = Str::lower(trim((string) data_get($meta, 'tenant_profile.cta', data_get($meta, 'item_brain.cta', ''))));

        if ($actualCta === '') {
            return [0.2, [
                'source' => 'brand_cta_match_heuristic',
                'mode' => 'heuristic',
            ], ['Manca una CTA finale esplicita.']];
        }

        if ($expectedCta === '') {
            return [0.7, [
                'source' => 'brand_cta_match_heuristic',
                'mode' => 'heuristic',
            ], []];
        }

        $expectedAnchor = Str::before($expectedCta, ' ');
        $score = $expectedAnchor !== '' && Str::contains($actualCta, $expectedAnchor) ? 0.9 : 0.55;
        $warnings = $score < 0.72 ? ['La CTA non sembra del tutto allineata alla CTA principale del brand.'] : [];

        return [$this->normalizeScore($score), [
            'source' => 'brand_cta_match_heuristic',
            'mode' => 'heuristic',
        ], $warnings];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>,3:array<int,string>,4:bool}
     */
    private function referenceMatchScore(array $meta): array
    {
        $validation = $this->resolveReferenceValidation($meta);
        $referencesExpected = $this->referencesExpected($meta);

        if (!is_array($validation)) {
            $warnings = $referencesExpected
                ? ['Reference match non validato automaticamente sui riferimenti reali.']
                : [];

            return [null, [
                'source' => 'not_available',
                'mode' => 'missing',
            ], $warnings, [], $referencesExpected];
        }

        $allPresent = (bool) ($validation['all_present'] ?? false);
        $confidence = $this->normalizeScore((float) ($validation['confidence'] ?? 0.0)) ?? 0.0;
        $missing = count((array) ($validation['missing_indexes'] ?? []));

        $score = $allPresent
            ? max(0.72, $confidence)
            : max(0.05, min(0.45, ($confidence * 0.45) - ($missing * 0.03)));

        $warnings = [];
        $blocking = [];
        if (!$allPresent) {
            $warnings[] = 'La validazione reference-aware segnala riferimenti mancanti o non sufficientemente riconoscibili.';
        }

        return [$this->normalizeScore($score), [
            'source' => 'reference_validation.confidence',
            'mode' => 'validated',
            'all_present' => $allPresent,
        ], $warnings, $blocking, $referencesExpected];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function visualIdentityScore(ContentItem $item, array $meta, ?float $referenceMatchScore, bool $referencesExpected): array
    {
        if ($referenceMatchScore !== null) {
            return [$referenceMatchScore, [
                'source' => 'reference_match_score',
                'mode' => 'validated',
            ], []];
        }

        if (!$this->hasVisualOutput($item, $meta)) {
            return [0.0, [
                'source' => 'missing_visual_output',
                'mode' => 'missing',
            ], []];
        }

        $visualSource = Str::lower(trim((string) data_get($meta, 'image_generation.source', data_get($meta, 'video_generation.reference_reason', 'text_to_visual'))));
        $grounded = Str::contains($visualSource, 'brand') || Str::contains($visualSource, 'reference') || $referencesExpected;
        $score = $grounded ? 0.66 : 0.58;
        $warnings = ['Visual identity score stimato in modo euristico: manca una validazione reference-aware finale.'];

        return [$this->normalizeScore($score), [
            'source' => $grounded ? 'grounded_visual_generation_heuristic' : 'visual_output_presence_heuristic',
            'mode' => 'heuristic',
        ], $warnings];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function realismScore(ContentItem $item, array $meta, ?GenerationRun $run = null): array
    {
        if (!$this->hasVisualOutput($item, $meta)) {
            return [0.0, [
                'source' => 'missing_visual_output',
                'mode' => 'missing',
            ], []];
        }

        if (trim((string) data_get($meta, 'image_generation.fallback', data_get($meta, 'image_fallback', ''))) === 'local_placeholder') {
            return [0.0, [
                'source' => 'local_placeholder_fallback',
                'mode' => 'heuristic',
            ], ['Il visual finale e un placeholder locale, quindi il realismo non e valutabile come output AI reale.']];
        }

        $brief = (string) data_get($meta, 'manual_brief', (string) ($item->caption ?? ''));
        $visualPrompt = trim((string) ($item->ai_image_prompt ?? data_get($meta, 'video_prompt', '')));
        $forcePhotorealism = ImagePromptRealismGuard::shouldForcePhotorealism($brief, $visualPrompt);
        $promptNormalized = Str::lower($visualPrompt);
        $hasRealismCues = Str::contains($promptNormalized, [
            'photoreal',
            'live-action',
            'real skin',
            'realistic',
            'natural',
            'cinematic',
        ]);

        $score = $forcePhotorealism ? 0.78 : 0.64;
        if ($hasRealismCues) {
            $score += 0.05;
        }
        if ($this->boolFromRunOrMeta($run, 'fallback_used', $meta, ['image_generation.fallback', 'video_generation.provider_fallback'])) {
            $score -= 0.08;
        }
        if ($this->boolFromRunOrMeta($run, 'downgrade_used', $meta, ['video_generation.extended_fallback', 'image_fallback'])) {
            $score -= 0.08;
        }

        return [$this->normalizeScore($score), [
            'source' => 'prompt_realism_guard_heuristic',
            'mode' => 'heuristic',
            'forced_photorealism' => $forcePhotorealism,
        ], ['Realism score basato su guard-rails del prompt e sul tipo di output, non su una validazione visuale dedicata.']];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function professionalismScore(ContentItem $item, array $meta): array
    {
        $brandAlignment = data_get($meta, 'text_alignment_review.llm.brand_alignment_score');
        $overall = data_get($meta, 'text_alignment_review.overall_score');
        $hardViolations = count((array) data_get($meta, 'text_alignment_review.heuristic.hard_rule_violations', []));
        $guardrailsCount = count((array) data_get($meta, 'item_brain.professionality_guardrails', []));
        $assetConfidence = is_numeric(data_get($meta, 'asset_scoring.identity_confidence'))
            ? (float) data_get($meta, 'asset_scoring.identity_confidence')
            : null;

        if (is_numeric($brandAlignment) || is_numeric($overall)) {
            $score = is_numeric($brandAlignment) ? (float) $brandAlignment : (float) $overall;
            if ($hardViolations > 0) {
                $score -= min(0.32, $hardViolations * 0.12);
            }
            if ($guardrailsCount > 0) {
                $score += min(0.04, $guardrailsCount * 0.01);
            }
            if (is_numeric($assetConfidence)) {
                $score = ($score * 0.85) + (((float) $assetConfidence) * 0.15);
            }

            return [$this->normalizeScore($score), [
                'source' => 'text_alignment_review + professionality_guardrails + asset_scoring',
                'mode' => 'validated',
            ], []];
        }

        $signals = array_filter([
            trim((string) ($item->ai_caption ?? '')),
            trim((string) ($item->ai_cta ?? '')),
            trim((string) $this->contentStrategyValue($meta, 'hook_meta.main_hook', '')),
        ], fn ($value) => $value !== '');

        if ($signals === []) {
            return [null, [
                'source' => 'not_available',
                'mode' => 'missing',
            ], ['Professionalism score non disponibile: mancano copy e segnali strategici sufficienti.']];
        }

        // Legge l'industry dal knowledge pack salvato in meta durante la generazione
        $industry = strtolower(trim((string) data_get($meta, 'knowledge_pack.brand_basics.industry', '')));

        $combined = implode(' ', $signals);
        $score = 0.74;
        if ($this->containsBannedHookFragment($combined)) {
            $score -= 0.28;
        }
        // Passa l'industry per applicare le regole specifiche del settore
        if ($this->containsAggressiveLanguage($combined, $industry)) {
            $score -= 0.18;
        }
        if ($this->exclamationMarks($combined) > $this->maxExclamationMarksForIndustry($industry)) {
            $score -= 0.08;
        }
        if ($this->uppercaseRatio($combined) > 0.3) {
            $score -= 0.12;
        }
        if (is_numeric($assetConfidence)) {
            $score = ($score * 0.85) + (((float) $assetConfidence) * 0.15);
        }

        return [$this->normalizeScore($score), [
            'source' => 'copy_and_hook_professionalism_heuristic',
            'mode' => 'heuristic',
        ], ['Professionalism score stimato in modo euristico: manca una review testuale strutturata del copy finale.']];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function trendRelevanceScore(ContentItem $item, array $meta): array
    {
        $trendLabel = trim((string) data_get($meta, 'content_strategy.selection_context.trend_relevance', data_get($meta, 'viral_angle.trend_relevance', '')));
        $trendConfidence = data_get($meta, 'item_brain.trend_confidence');
        $trendOpportunity = (array) data_get($meta, 'item_brain.trend_opportunity', []);
        $viralPotential = data_get($trendOpportunity, 'viral_potential_score', data_get($meta, 'strategy.trend_intelligence.summary.avg_viral_potential_score'));
        $hasStructuredTrendSignal = !empty($trendOpportunity)
            || is_numeric($trendConfidence)
            || is_numeric($viralPotential);

        $inputs = [];
        if ($trendLabel !== '') {
            $inputs[] = $this->trendLabelScore($trendLabel);
        }
        if (is_numeric($trendConfidence)) {
            $inputs[] = (float) $trendConfidence;
        }
        if (is_numeric($viralPotential)) {
            $inputs[] = min(1.0, max(0.0, ((float) $viralPotential * 0.8) + 0.1));
        }

        if ($inputs !== []) {
            $score = array_sum($inputs) / count($inputs);
            if ($this->trendExpected($meta) && is_numeric($trendConfidence) && (float) $trendConfidence < 0.45) {
                $score -= 0.22;
            }

            if ($hasStructuredTrendSignal) {
                return [$this->normalizeScore($score), [
                    'source' => 'content_strategy.selection_context + trend_intelligence',
                    'mode' => 'validated',
                ], []];
            }

            return [$this->normalizeScore($score), [
                'source' => 'content_strategy.selection_context_without_structured_trend_signal',
                'mode' => 'heuristic',
            ], ['Trend relevance score stimato in modo euristico: esiste solo il trend label strategico senza una trend opportunity o confidence strutturata.']];
        }

        if ($this->trendExpected($meta)) {
            return [0.42, [
                'source' => 'trend_expectation_without_structured_signal',
                'mode' => 'heuristic',
            ], ['Trend relevance score stimato in modo euristico: manca una trend opportunity strutturata o una confidence esplicita.']];
        }

        return [null, [
            'source' => 'trend_not_requested_or_missing',
            'mode' => 'missing',
        ], []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function trendBrandFitScore(ContentItem $item, array $meta): array
    {
        $brandFit = data_get($meta, 'item_brain.trend_opportunity.brand_fit_score', data_get($meta, 'strategy.trend_intelligence.summary.avg_brand_fit_score'));
        $execution = data_get($meta, 'item_brain.trend_opportunity.execution_feasibility_score');
        $assetConfidence = data_get($meta, 'asset_scoring.identity_confidence');

        if (is_numeric($brandFit)) {
            $score = (float) $brandFit * 0.72;
            if (is_numeric($execution)) {
                $score += ((float) $execution) * 0.18;
            }
            if (is_numeric($assetConfidence)) {
                $score += ((float) $assetConfidence) * 0.10;
            }

            return [$this->normalizeScore($score), [
                'source' => 'trend_opportunity.brand_fit_score + asset_scoring',
                'mode' => 'validated',
            ], []];
        }

        if ($this->trendExpected($meta) && is_numeric($assetConfidence)) {
            return [$this->normalizeScore(0.42 + (((float) $assetConfidence) * 0.22)), [
                'source' => 'asset_scoring_supported_trend_fit_heuristic',
                'mode' => 'heuristic',
            ], ['Trend brand fit score stimato in modo euristico: manca un brand_fit_score strutturato della trend opportunity.']];
        }

        return [null, [
            'source' => 'trend_brand_fit_not_available',
            'mode' => 'missing',
        ], []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $storyboardReview
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function hookStrengthScore(ContentItem $item, array $meta, array $storyboardReview): array
    {
        $mainHook = trim((string) $this->contentStrategyValue($meta, 'hook_meta.main_hook', ''));
        $alternativeHook = trim((string) $this->contentStrategyValue($meta, 'hook_meta.alternative_hook', ''));
        $openingStructure = trim((string) $this->contentStrategyValue($meta, 'hook_meta.platform_specific_opening_structure', ''));
        $authorityCue = trim((string) $this->contentStrategyValue($meta, 'hook_meta.authority_cue', ''));
        $trustCue = trim((string) $this->contentStrategyValue($meta, 'hook_meta.proof_or_trust_cue', ''));
        $primaryHook = $mainHook !== ''
            ? $mainHook
            : trim((string) data_get($meta, 'content_structure_meta.video_segments.hook_0_3', ''));

        if ($primaryHook === '') {
            return [null, [
                'source' => 'hook_meta_missing',
                'mode' => 'missing',
            ], []];
        }

        $score = 0.58;
        $length = mb_strlen($primaryHook, 'UTF-8');
        $idealMin = (int) config('ai_quality.hook.ideal_min_chars', 18);
        $idealMax = (int) config('ai_quality.hook.ideal_max_chars', 110);
        $hardMax = (int) config('ai_quality.hook.hard_max_chars', 140);
        $combinedHookSignals = trim(implode(' ', array_filter([
            $mainHook,
            $alternativeHook,
            $openingStructure,
            $authorityCue,
            $trustCue,
        ])));

        if ($length >= $idealMin && $length <= $idealMax) {
            $score += 0.12;
        } elseif ($length > $hardMax || $length < 8) {
            $score -= 0.12;
        }
        if ($alternativeHook !== '') {
            $score += 0.06;
        }
        if ($openingStructure !== '') {
            $score += 0.08;
        }
        if ($authorityCue !== '' || $trustCue !== '') {
            $score += 0.07;
        }
        if ((bool) ($storyboardReview['hook_scene_present'] ?? false)) {
            $score += 0.08;
        }
        if ($this->containsBannedHookFragment($combinedHookSignals)) {
            $score -= 0.42;
        }
        if ($this->containsAggressiveLanguage($combinedHookSignals)) {
            $score -= 0.18;
        }

        $maxExclamations = (int) config('ai_quality.professionalism.max_exclamation_marks', 2);
        $exclamations = $this->exclamationMarks($combinedHookSignals);
        if ($exclamations > $maxExclamations) {
            $score -= min(0.18, 0.06 * ($exclamations - $maxExclamations));
        }

        $uppercaseRatio = $this->uppercaseRatio($primaryHook);
        if ($uppercaseRatio > 0.45) {
            $score -= 0.18;
        } elseif ($uppercaseRatio > 0.3) {
            $score -= 0.10;
        }

        return [$this->normalizeScore($score), [
            'source' => 'hook_meta + storyboard_hook_presence',
            'mode' => 'heuristic',
        ], []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $storyboardReview
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function firstSecondsStrengthScore(ContentItem $item, array $meta, array $storyboardReview): array
    {
        if (!$this->isVideoFormat((string) $item->format)) {
            return [null, [
                'source' => 'not_applicable_non_video',
                'mode' => 'missing',
            ], []];
        }

        $sceneList = array_values(array_filter((array) data_get($meta, 'storyboard_meta.scene_list', []), fn ($scene) => is_array($scene)));
        if ($sceneList !== []) {
            $firstScene = (array) ($sceneList[0] ?? []);
            $safeArea = trim((string) data_get($firstScene, 'text_overlay.safe_area', ''));
            $hasOverlay = trim((string) data_get($firstScene, 'text_overlay.text', '')) !== '';
            $hasVoiceover = trim((string) ($firstScene['voiceover_segment'] ?? '')) !== '';
            $start = (int) data_get($firstScene, 'timing_window.start_ms', 0);
            $end = (int) data_get($firstScene, 'timing_window.end_ms', 0);
            $duration = max(0, $end - $start);
            $score = (string) ($firstScene['scene_type'] ?? '') === 'hook' ? 0.56 : 0.36;

            if ($start === 0) {
                $score += 0.1;
            }
            if ($duration > 0 && $duration <= 3000) {
                $score += 0.12;
            } elseif ($duration > 3000) {
                $score -= 0.06;
            }
            if ($hasOverlay) {
                $score += 0.07;
            }
            if ($hasVoiceover) {
                $score += 0.06;
            }
            if ($safeArea !== '' && in_array(Str::lower($safeArea), ['upper_third', 'center_safe'], true)) {
                $score += 0.05;
            }
            if (trim((string) data_get($meta, 'content_structure_meta.video_segments.hook_0_3', '')) !== '') {
                $score += 0.06;
            }

            return [$this->normalizeScore($score), [
                'source' => 'storyboard_meta.scene_list[0]',
                'mode' => 'validated',
            ], []];
        }

        if (trim((string) data_get($meta, 'reel_blueprint.hook', data_get($meta, 'content_structure_meta.video_segments.hook_0_3', ''))) !== '') {
            return [0.6, [
                'source' => 'reel_blueprint_hook_fallback',
                'mode' => 'heuristic',
            ], ['First seconds strength stimato in modo euristico: manca storyboard_meta ma esiste una hook direction di base.']];
        }

        return [null, [
            'source' => 'video_hook_structure_missing',
            'mode' => 'missing',
        ], []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $overlayReview
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function overlayReadabilityScore(array $meta, array $overlayReview): array
    {
        $score = data_get($overlayReview, 'overall_score');
        if (is_numeric($score)) {
            return [$this->normalizeScore((float) $score), [
                'source' => 'overlay_meta.readability.overall_score',
                'mode' => 'validated',
            ], []];
        }

        return [null, [
            'source' => 'overlay_meta.readability',
            'mode' => 'missing',
        ], []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $overlayReview
     * @param  array<string, mixed>  $storyboardReview
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function mobileLegibilityScore(ContentItem $item, array $meta, array $overlayReview, array $storyboardReview): array
    {
        $score = data_get($overlayReview, 'mobile_readability');
        if (is_numeric($score)) {
            return [$this->normalizeScore((float) $score), [
                'source' => 'overlay_meta.readability.mobile_readability',
                'mode' => 'validated',
            ], []];
        }

        $sceneList = array_values(array_filter((array) data_get($meta, 'storyboard_meta.scene_list', []), fn ($scene) => is_array($scene)));
        if ($sceneList !== []) {
            $total = 0.0;
            $count = 0;
            foreach ($sceneList as $scene) {
                $text = trim((string) data_get($scene, 'text_overlay.text', ''));
                if ($text === '') {
                    continue;
                }

                $safeArea = Str::lower(trim((string) data_get($scene, 'text_overlay.safe_area', '')));
                $maxLines = max(1, (int) data_get($scene, 'text_overlay.max_lines', 2));
                $length = mb_strlen($text, 'UTF-8');
                $scoreRow = 0.72;
                if (!in_array($safeArea, ['upper_third', 'center_safe', 'lower_third'], true)) {
                    $scoreRow -= 0.08;
                }
                if ($length > 52) {
                    $scoreRow -= 0.12;
                }
                if ($maxLines > 2) {
                    $scoreRow -= 0.08;
                }
                $total += $scoreRow;
                $count++;
            }

            if ($count > 0) {
                return [$this->normalizeScore($total / $count), [
                    'source' => 'storyboard_overlay_mobile_heuristic',
                    'mode' => 'heuristic',
                ], ['Mobile legibility score stimato in modo euristico dalle scene storyboard: manca una lettura overlay validata.']];
            }
        }

        return [null, [
            'source' => $this->isVideoFormat((string) $item->format) ? 'overlay_mobile_not_available' : 'not_applicable_without_overlay',
            'mode' => 'missing',
        ], []];
    }

    /**
     * @param  array<string, mixed>  $components
     * @return array{0:?float,1:array<string,mixed>,2:array<int,string>}
     */
    private function viralReadinessScore(array $meta, array $components): array
    {
        $weights = (array) config('ai_quality.viral_readiness_weights', []);
        $weighted = 0.0;
        $weightTotal = 0.0;
        $used = [];

        foreach ($weights as $key => $weight) {
            $value = $components[$key] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            if (str_starts_with((string) $key, 'trend_') && !$this->trendExpected($meta)) {
                continue;
            }
            if ((string) $key === 'first_seconds_strength_score' && empty((array) data_get($meta, 'storyboard_meta.scene_list', [])) && !$this->isVideoMeta($meta)) {
                continue;
            }

            $weighted += ((float) $value) * ((float) $weight);
            $weightTotal += (float) $weight;
            $used[] = (string) $key;
        }

        if ($weightTotal <= 0.0) {
            return [null, [
                'source' => 'viral_components_not_available',
                'mode' => 'missing',
            ], []];
        }

        return [$this->normalizeScore($weighted / $weightTotal), [
            'source' => 'aggregate_of_hook_trend_overlay_professionalism',
            'mode' => 'heuristic',
            'components_used' => $used,
        ], []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function resolveReferenceValidation(array $meta): ?array
    {
        $candidates = [
            data_get($meta, 'video_generation.reference_validation'),
            data_get($meta, 'video_generation.composition_reference.validation'),
            data_get($meta, 'image_generation.alignment_review'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_key_exists('all_present', $candidate) && array_key_exists('confidence', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function referencesExpected(array $meta): bool
    {
        $paths = array_merge(
            array_map('strval', (array) data_get($meta, 'image_generation.brand_source_paths', [])),
            array_map('strval', (array) data_get($meta, 'video_generation.reference_paths', [])),
            array_map('strval', (array) data_get($meta, 'image_references.selected_paths', [])),
            array_map('strval', (array) data_get($meta, 'asset_variables.resolved_asset_paths', []))
        );

        return !empty(array_filter($paths, fn ($path) => trim((string) $path) !== ''));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function hasVisualOutput(ContentItem $item, array $meta): bool
    {
        if (trim((string) ($item->ai_image_path ?? '')) !== '') {
            return true;
        }

        if (trim((string) data_get($meta, 'video_generation.video_path', '')) !== '') {
            return true;
        }

        foreach ((array) ($item->assets ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $type = Str::lower(trim((string) ($asset['type'] ?? '')));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path !== '' && (str_contains($type, 'ai_generated') || $type === 'demo_image')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $metaKeys
     */
    private function boolFromRunOrMeta(?GenerationRun $run, string $runKey, array $meta, array $metaKeys): bool
    {
        if ($run && $run->{$runKey} !== null) {
            return (bool) $run->{$runKey};
        }

        foreach ($metaKeys as $key) {
            $value = data_get($meta, $key);
            if (is_bool($value)) {
                if ($value) {
                    return true;
                }
                continue;
            }

            if (is_array($value)) {
                if (!empty($value)) {
                    return true;
                }
                continue;
            }

            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function advancedScoreWarnings(
        ContentItem $item,
        array $meta,
        ?float $professionalismScore,
        ?float $trendRelevanceScore,
        ?float $trendBrandFitScore,
        ?float $hookStrengthScore,
        ?float $firstSecondsStrengthScore,
        ?float $overlayReadabilityScore,
        ?float $mobileLegibilityScore,
        ?float $viralReadinessScore
    ): array {
        $warnings = [];
        $trendExpected = $this->trendExpected($meta);
        $overlayEnabled = (string) data_get($meta, 'overlay_meta.mode', 'auto') !== 'off'
            && !empty((array) data_get($meta, 'overlay_meta.templates', []));

        if (is_numeric($professionalismScore) && $professionalismScore < $this->scoreThreshold('professionalism', 'warning', 0.64)) {
            $warnings[] = 'Professionalism score sotto soglia: copy o hook da rivedere per mantenere una percezione premium.';
        }
        if (is_numeric($hookStrengthScore) && $hookStrengthScore < $this->scoreThreshold('hook_strength', 'warning', 0.58)) {
            $warnings[] = 'Hook strength score basso: opening poco forte o poco distintivo.';
        }
        if ($this->isVideoFormat((string) $item->format) && is_numeric($firstSecondsStrengthScore) && $firstSecondsStrengthScore < $this->scoreThreshold('first_seconds_strength', 'warning', 0.62)) {
            $warnings[] = 'First seconds strength score sotto soglia: i primi secondi del video non sono abbastanza forti.';
        }
        if ($trendExpected && is_numeric($trendRelevanceScore) && $trendRelevanceScore < $this->scoreThreshold('trend_relevance', 'warning', 0.6)) {
            $warnings[] = 'Trend relevance score basso per un contenuto che dovrebbe essere trend-aware.';
        }
        if ($trendExpected && is_numeric($trendBrandFitScore) && $trendBrandFitScore < $this->scoreThreshold('trend_brand_fit', 'warning', 0.66)) {
            $warnings[] = 'Trend brand fit score basso: il trend non sembra abbastanza coerente con il brand.';
        }
        if ($overlayEnabled && is_numeric($overlayReadabilityScore) && $overlayReadabilityScore < $this->scoreThreshold('overlay_readability', 'warning', 0.62)) {
            $warnings[] = 'Overlay readability score basso: testo non abbastanza leggibile per uso social/mobile.';
        }
        if ($overlayEnabled && is_numeric($mobileLegibilityScore) && $mobileLegibilityScore < $this->scoreThreshold('mobile_legibility', 'warning', 0.68)) {
            $warnings[] = 'Mobile legibility score basso: overlay non ottimizzato per la fruizione da smartphone.';
        }
        if (is_numeric($viralReadinessScore) && $viralReadinessScore < $this->scoreThreshold('viral_readiness', 'warning', 0.6)) {
            $warnings[] = 'Viral readiness score basso: hook, trend, overlay o first-seconds non stanno ancora lavorando bene insieme.';
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function advancedScoreBlockingReasons(
        ContentItem $item,
        array $meta,
        ?float $professionalismScore,
        ?float $trendRelevanceScore,
        ?float $trendBrandFitScore,
        ?float $hookStrengthScore,
        ?float $firstSecondsStrengthScore,
        ?float $overlayReadabilityScore,
        ?float $mobileLegibilityScore
    ): array {
        $blocking = [];
        $trendExpected = $this->trendExpected($meta);
        $overlayEnabled = (string) data_get($meta, 'overlay_meta.mode', 'auto') !== 'off'
            && !empty((array) data_get($meta, 'overlay_meta.templates', []));

        if (is_numeric($professionalismScore) && $professionalismScore < $this->scoreThreshold('professionalism', 'blocked', 0.36)) {
            $blocking[] = 'Professionalism score troppo basso: il contenuto risulta troppo aggressivo o poco professionale.';
        }
        if (is_numeric($hookStrengthScore) && $hookStrengthScore < $this->scoreThreshold('hook_strength', 'blocked', 0.34)) {
            $blocking[] = 'Hook troppo debole per la pubblicazione diretta.';
        }
        if ($this->isVideoFormat((string) $item->format) && is_numeric($firstSecondsStrengthScore) && $firstSecondsStrengthScore < $this->scoreThreshold('first_seconds_strength', 'blocked', 0.38)) {
            $blocking[] = 'I primi secondi del video sono troppo deboli per un output social-native.';
        }
        if ($trendExpected && is_numeric($trendRelevanceScore) && $trendRelevanceScore < $this->scoreThreshold('trend_relevance', 'blocked', 0.42)) {
            $blocking[] = 'Contenuto fuori trend o poco rilevante rispetto a un obiettivo trend-aware.';
        }
        if ($trendExpected && is_numeric($trendBrandFitScore) && $trendBrandFitScore < $this->scoreThreshold('trend_brand_fit', 'blocked', 0.46)) {
            $blocking[] = 'Trend brand fit troppo basso: il contenuto non e abbastanza coerente con il brand pur essendo trend-aware.';
        }
        if ($overlayEnabled && is_numeric($overlayReadabilityScore) && $overlayReadabilityScore < $this->scoreThreshold('overlay_readability', 'blocked', 0.38)) {
            $blocking[] = 'Overlay troppo poco leggibile per la pubblicazione diretta.';
        }
        if ($overlayEnabled && is_numeric($mobileLegibilityScore) && $mobileLegibilityScore < $this->scoreThreshold('mobile_legibility', 'blocked', 0.42)) {
            $blocking[] = 'Overlay non sufficientemente leggibile su mobile.';
        }

        return array_values(array_unique(array_filter($blocking)));
    }

    private function scoreThreshold(string $key, string $level, float $default): float
    {
        return (float) config("ai_quality.score_thresholds.{$key}.{$level}", $default);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function trendExpected(array $meta): bool
    {
        $strategyType = trim((string) $this->contentStrategyValue($meta, 'content_strategy_type', data_get($meta, 'content_strategy.strategy_type', '')));
        $editorialMode = Str::lower(trim((string) data_get($meta, 'item_brain.editorial_mode', '')));
        $usageMode = Str::lower(trim((string) data_get($meta, 'item_brain.trend_usage_mode', '')));
        $trendLabel = Str::lower(trim((string) data_get($meta, 'content_strategy.selection_context.trend_relevance', data_get($meta, 'viral_angle.trend_relevance', ''))));
        $hasTrendOpportunity = !empty((array) data_get($meta, 'item_brain.trend_opportunity', []));

        return $strategyType === 'trend-aware'
            || in_array($editorialMode, ['trend-aware', 'reactive'], true)
            || in_array($usageMode, ['trend_safe_adaptation', 'format_acceleration', 'reactive_commentary'], true)
            || in_array($trendLabel, ['medium', 'high'], true)
            || $hasTrendOpportunity;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function isVideoMeta(array $meta): bool
    {
        return trim((string) data_get($meta, 'video_generation.video_path', '')) !== ''
            || !empty((array) data_get($meta, 'storyboard_meta.scene_list', []))
            || !empty((array) data_get($meta, 'reel_blueprint.shots', []));
    }

    private function trendLabelScore(string $label): float
    {
        return match (Str::lower(trim($label))) {
            'high' => 0.88,
            'medium' => 0.66,
            'low' => 0.36,
            default => 0.5,
        };
    }

    private function containsBannedHookFragment(string $text): bool
    {
        $normalized = Str::lower(trim($text));
        foreach ((array) config('content_strategy.guardrails.banned_hook_fragments', []) as $fragment) {
            $fragment = Str::lower(trim((string) $fragment));
            if ($fragment !== '' && Str::contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se il testo contiene linguaggio aggressivo.
     *
     * Controlla sia le regole generiche sia quelle dell'industry del tenant.
     * Le regole industry vengono lette da ai_quality.industry_overrides.{industry}.aggressive_fragments.
     */
    private function containsAggressiveLanguage(string $text, string $industry = ''): bool
    {
        $normalized = Str::lower(trim($text));

        // Frammenti generici (validi per tutti i settori)
        $fragments = array_merge(
            (array) config('ai_quality.professionalism.aggressive_fragments', []),
            $this->industryAggressiveFragments($industry)
        );

        foreach ($fragments as $fragment) {
            $fragment = Str::lower(trim((string) $fragment));
            if ($fragment !== '' && Str::contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restituisce i frammenti aggressivi aggiuntivi per una data industry.
     *
     * @return array<int, string>
     */
    private function industryAggressiveFragments(string $industry): array
    {
        $industry = strtolower(trim($industry));
        if ($industry === '') {
            return [];
        }

        return (array) config("ai_quality.industry_overrides.{$industry}.aggressive_fragments", []);
    }

    /**
     * Restituisce il limite di esclamativi per una data industry.
     * Usa il default generico se l'industry non ha override.
     */
    private function maxExclamationMarksForIndustry(string $industry): int
    {
        $industry = strtolower(trim($industry));
        $override = config("ai_quality.industry_overrides.{$industry}.max_exclamation_marks");

        if (is_numeric($override)) {
            return (int) $override;
        }

        return (int) config('ai_quality.professionalism.max_exclamation_marks', 2);
    }

    private function exclamationMarks(string $text): int
    {
        return substr_count($text, '!');
    }

    private function uppercaseRatio(string $text): float
    {
        $letters = preg_replace('/[^[:alpha:]]/u', '', $text) ?? '';
        $total = mb_strlen($letters, 'UTF-8');
        if ($total === 0) {
            return 0.0;
        }

        preg_match_all('/\p{Lu}/u', $letters, $matches);

        return count((array) ($matches[0] ?? [])) / $total;
    }

    private function normalizeScore(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(max(0.0, min(1.0, $value)), 4);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function trendIntelligenceReviewSource(array $meta): array
    {
        return [
            'source' => 'strategy.trend_intelligence_and_item_brain.trend_opportunity',
            'mode' => !empty((array) data_get($meta, 'item_brain.trend_opportunity', [])) || !empty((array) data_get($meta, 'strategy.trend_intelligence', []))
                ? 'validated'
                : 'missing',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function trendIntelligenceWarnings(array $meta): array
    {
        $warnings = [];
        $trendRequested = trim((string) data_get($meta, 'item_brain.trend_bridge', '')) !== ''
            || !empty((array) data_get($meta, 'item_brain.trend_opportunity', []));
        $trendOpportunity = (array) data_get($meta, 'item_brain.trend_opportunity', []);
        $riskFlags = array_values(array_filter(array_map('strval', (array) data_get($trendOpportunity, 'risk_flags', []))));
        $brandFit = data_get($trendOpportunity, 'brand_fit_score');
        $execution = data_get($trendOpportunity, 'execution_feasibility_score');
        $usageMode = trim((string) data_get($meta, 'item_brain.trend_usage_mode', ''));
        $confidence = data_get($meta, 'item_brain.trend_confidence');
        $trendBasis = (array) data_get($meta, 'item_brain.trend_basis', []);
        $guardrails = array_values(array_filter(array_map('strval', (array) data_get($meta, 'item_brain.professionality_guardrails', []))));
        $engagementGoal = trim((string) data_get($meta, 'item_brain.expected_engagement_goal', ''));

        if ($trendRequested && empty($trendOpportunity)) {
            $warnings[] = 'Trend richiesto ma senza una trend opportunity strutturata e valutata.';
        }

        if ($trendRequested && empty($trendBasis)) {
            $warnings[] = 'Trend richiesto ma senza un trend_basis strutturato per spiegare perche usarlo ora.';
        }

        if ($trendRequested && $usageMode === '') {
            $warnings[] = 'Trend richiesto ma senza trend_usage_mode esplicito.';
        }

        if ($trendRequested && empty($guardrails)) {
            $warnings[] = 'Trend richiesto ma senza professionality guardrails espliciti.';
        }

        if ($trendRequested && $engagementGoal === '') {
            $warnings[] = 'Trend richiesto ma senza expected_engagement_goal esplicito.';
        }

        if (!empty($riskFlags)) {
            $warnings[] = 'Trend opportunity con risk flags: ' . implode(', ', array_slice($riskFlags, 0, 3)) . '.';
        }

        if (is_numeric($brandFit) && (float) $brandFit < 0.62) {
            $warnings[] = 'Brand fit del trend sotto soglia premium: serve review editoriale.';
        }

        if (is_numeric($execution) && (float) $execution < 0.58) {
            $warnings[] = 'Trend interessante ma poco eseguibile con gli asset o il setup attuale del tenant.';
        }

        if ($usageMode === 'reactive_commentary' && is_numeric($confidence) && (float) $confidence < 0.65) {
            $warnings[] = 'Uso reactive del trend con confidence bassa: meglio ridurre aggressivita o tornare a trend-safe adaptation.';
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function trendIntelligenceBlockingReasons(array $meta): array
    {
        $trendOpportunity = (array) data_get($meta, 'item_brain.trend_opportunity', []);
        if (empty($trendOpportunity)) {
            return [];
        }

        $riskFlags = array_map(
            fn ($value) => strtolower(trim((string) $value)),
            (array) data_get($trendOpportunity, 'risk_flags', [])
        );
        $blockedFlags = array_map(
            fn ($value) => strtolower(trim((string) $value)),
            (array) config('trends.risk_block_flags', [])
        );

        if (!empty(array_intersect($riskFlags, $blockedFlags))) {
            return ['La trend opportunity contiene un rischio incompatibile con il brand o con la publish readiness.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function creativeDirectionReviewSource(array $meta): array
    {
        return [
            'source' => 'strategy.creative_direction',
            'mode' => !empty((array) data_get($meta, 'strategy.creative_direction', [])) ? 'validated' : 'missing',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function creativeDirectionWarnings(array $meta): array
    {
        $warnings = [];
        $creativeDirection = (array) data_get($meta, 'strategy.creative_direction', []);
        $overlayBrief = trim((string) data_get($meta, 'item_brain.overlay_brief', ''));
        $trendBridge = trim((string) data_get($meta, 'item_brain.trend_bridge', ''));
        $continuityBrief = trim((string) data_get($meta, 'item_brain.continuity_brief', ''));

        if ($overlayBrief !== '' && trim((string) data_get($creativeDirection, 'typography_system.overlay_mode', '')) === '') {
            $warnings[] = 'Overlay typography richiesta ma non supportata da una typography policy strutturata.';
        }

        if ($trendBridge !== '' && trim((string) data_get($creativeDirection, 'trend_policy.usage_mode', '')) === '') {
            $warnings[] = 'Uso trend richiesto ma privo di una trend policy brand-safe strutturata.';
        }

        if ($continuityBrief !== '' && empty((array) data_get($creativeDirection, 'continuity_rules', []))) {
            $warnings[] = 'Continuita asset richiesta ma senza continuity rules strategiche strutturate.';
        }

        if ($overlayBrief !== '') {
            $warnings[] = 'Overlay typography predisposta come safe-area layout: verifica il testo finale prima della pubblicazione.';
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function contentStrategyReviewSource(array $meta): array
    {
        $strategyPresent = !empty((array) data_get($meta, 'content_strategy', []))
            || !empty((array) data_get($meta, 'hook_meta', []))
            || !empty((array) data_get($meta, 'item_brain.hook_meta', []));

        return [
            'source' => 'content_strategy_layer',
            'mode' => $strategyPresent ? 'validated' : 'missing',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function overlayReviewSource(array $meta): array
    {
        $overlayMeta = (array) data_get($meta, 'overlay_meta', []);
        $readability = (array) data_get($overlayMeta, 'readability', []);

        return [
            'source' => 'overlay_meta.readability',
            'mode' => $readability !== []
                ? 'validated'
                : ($overlayMeta !== [] ? 'heuristic' : 'missing'),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function storyboardReviewSource(array $meta): array
    {
        return [
            'source' => 'storyboard_meta.scene_list',
            'mode' => !empty((array) data_get($meta, 'storyboard_meta.scene_list', [])) ? 'validated' : 'missing',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function contentStrategyWarnings(ContentItem $item, array $meta): array
    {
        $warnings = [];
        $strategyType = trim((string) $this->contentStrategyValue($meta, 'content_strategy_type', data_get($meta, 'content_strategy.strategy_type', '')));
        $hookMeta = (array) $this->contentStrategyValue($meta, 'hook_meta', []);
        $authoritySignals = (array) $this->contentStrategyValue($meta, 'authority_signals', []);
        $trustSignals = (array) $this->contentStrategyValue($meta, 'trust_signals', []);
        $contentStructureMeta = (array) $this->contentStrategyValue($meta, 'content_structure_meta', []);
        $videoSegments = (array) data_get($contentStructureMeta, 'video_segments', []);

        if (empty($hookMeta)) {
            $warnings[] = 'Manca hook_meta strutturato per guidare opening, angle e CTA del contenuto.';
        } else {
            if (trim((string) ($hookMeta['main_hook'] ?? '')) === '') {
                $warnings[] = 'hook_meta presente ma senza main_hook.';
            }
            if (trim((string) ($hookMeta['alternative_hook'] ?? '')) === '') {
                $warnings[] = 'hook_meta presente ma senza alternative_hook.';
            }
            if (trim((string) ($hookMeta['platform_specific_opening_structure'] ?? '')) === '') {
                $warnings[] = 'hook_meta presente ma senza platform_specific_opening_structure.';
            }
        }

        if (!$this->hasContentStrategyRootPersistence($meta)) {
            $warnings[] = 'Persistenza content strategy incompleta: attesi hook_meta, authority_signals, trust_signals, viral_angle e content_structure_meta a livello root.';
        }

        if (empty($authoritySignals) && in_array($strategyType, ['authoritative', 'conversion', 'social-proof'], true)) {
            $warnings[] = 'Mancano authority_signals espliciti per un contenuto che richiede forte credibilita percepita.';
        }

        if (empty($trustSignals) && in_array($strategyType, ['authoritative', 'conversion', 'social-proof', 'emotional-relatable'], true)) {
            $warnings[] = 'Mancano trust_signals espliciti per un contenuto che deve costruire fiducia o prova.';
        }

        if ($this->isVideoFormat((string) $item->format) && empty($videoSegments)) {
            $warnings[] = 'Per formato video/reel manca content_structure_meta.video_segments con hook 0-3s, sviluppo, payoff e CTA ending.';
        }

        $bannedFragments = array_values(array_filter(array_map(
            'strval',
            (array) config('content_strategy.guardrails.banned_hook_fragments', [])
        )));
        $hookTexts = [
            trim((string) ($hookMeta['main_hook'] ?? '')),
            trim((string) ($hookMeta['alternative_hook'] ?? '')),
        ];

        foreach ($hookTexts as $hookText) {
            $normalized = Str::lower($hookText);
            foreach ($bannedFragments as $fragment) {
                $fragment = Str::lower(trim($fragment));
                if ($fragment !== '' && Str::contains($normalized, $fragment)) {
                    $warnings[] = 'Un hook strutturato contiene un frammento non professionale o troppo marketer: ' . $fragment . '.';
                }
            }
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function overlayReview(array $meta): array
    {
        $overlayMeta = (array) data_get($meta, 'overlay_meta', []);
        $readability = (array) data_get($overlayMeta, 'readability', []);

        return [
            'enabled' => (string) data_get($overlayMeta, 'mode', 'auto') !== 'off' && !empty((array) data_get($overlayMeta, 'templates', [])),
            'mode' => (string) data_get($overlayMeta, 'mode', ''),
            'preset' => (string) data_get($overlayMeta, 'preset.key', ''),
            'template_count' => count((array) data_get($overlayMeta, 'templates', [])),
            'contrast_score' => data_get($readability, 'contrast_score'),
            'safe_area_score' => data_get($readability, 'safe_area_score'),
            'overlap_risk' => data_get($readability, 'overlap_risk'),
            'mobile_readability' => data_get($readability, 'mobile_readability'),
            'overall_score' => data_get($readability, 'overall_score'),
            'render_status' => (string) data_get($overlayMeta, 'rendering.status', ''),
            'render_applied' => (bool) data_get($overlayMeta, 'rendering.applied', false),
            'warnings' => array_values(array_filter(array_map('strval', (array) data_get($readability, 'warnings', [])))),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function storyboardReview(ContentItem $item, array $meta): array
    {
        $storyboard = (array) data_get($meta, 'storyboard_meta', []);
        $sceneList = array_values(array_filter(
            (array) ($storyboard['scene_list'] ?? []),
            fn ($scene) => is_array($scene)
        ));

        $hookScenePresent = false;
        $ctaScenePresent = false;
        $safeAreasValid = true;
        $identitySafe = true;
        $voiceoverSegmentCount = 0;
        $overlaySegmentCount = 0;

        foreach ($sceneList as $scene) {
            $sceneType = Str::lower(trim((string) ($scene['scene_type'] ?? 'development')));
            $overlay = (array) ($scene['text_overlay'] ?? []);
            $safeArea = Str::lower(trim((string) ($overlay['safe_area'] ?? '')));
            $position = Str::lower(trim((string) ($overlay['position'] ?? '')));
            $avoidRegions = array_values(array_filter(array_map('strval', (array) ($overlay['avoid_regions'] ?? []))));

            if ($sceneType === 'hook') {
                $hookScenePresent = true;
                if (!in_array($safeArea, ['upper_third', 'center_safe'], true)) {
                    $safeAreasValid = false;
                }
            }
            $ctaRole = Str::lower(trim((string) ($scene['cta_role'] ?? $scene['CTA_role'] ?? '')));

            if ($sceneType === 'cta' || $ctaRole === 'final_cta') {
                $ctaScenePresent = true;
                if ($safeArea !== 'lower_third') {
                    $safeAreasValid = false;
                }
            }
            if (trim((string) ($scene['voiceover_segment'] ?? '')) !== '') {
                $voiceoverSegmentCount++;
            }
            if (trim((string) ($overlay['text'] ?? '')) !== '') {
                $overlaySegmentCount++;
            }

            if ((bool) ($storyboard['identity_first'] ?? false)
                && !empty($avoidRegions)
                && in_array($position, ['center', 'center_left'], true)
                && count(array_intersect($avoidRegions, ['center_face_zone', 'hero_product_zone', 'brand_logo_zone'])) > 0
            ) {
                $identitySafe = false;
            }
        }

        return [
            'present' => $sceneList !== [],
            'scene_count' => count($sceneList),
            'hook_scene_present' => $hookScenePresent,
            'cta_scene_present' => $ctaScenePresent,
            'safe_areas_valid' => $safeAreasValid,
            'identity_safe' => $identitySafe,
            'identity_first' => (bool) ($storyboard['identity_first'] ?? false),
            'voiceover_segment_count' => $voiceoverSegmentCount,
            'overlay_segment_count' => $overlaySegmentCount,
            'total_duration_ms' => (int) ($storyboard['total_duration_ms'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $overlayReview
     * @return array<int, string>
     */
    private function overlayWarnings(ContentItem $item, array $meta, array $overlayReview): array
    {
        $warnings = [];
        $enabled = (bool) ($overlayReview['enabled'] ?? false);
        if (!$enabled) {
            return [];
        }

        $contrast = $overlayReview['contrast_score'] ?? null;
        $safeArea = $overlayReview['safe_area_score'] ?? null;
        $overlapRisk = $overlayReview['overlap_risk'] ?? null;
        $mobileReadability = $overlayReview['mobile_readability'] ?? null;
        $renderApplied = (bool) ($overlayReview['render_applied'] ?? false);

        if (!$renderApplied && $this->hasVisualOutput($item, $meta)) {
            $warnings[] = 'Overlay pianificato ma non ancora renderizzato sull asset finale.';
        }
        if (is_numeric($contrast) && (float) $contrast < 0.62) {
            $warnings[] = 'Overlay contrast score basso: serve review grafica.';
        }
        if (is_numeric($safeArea) && (float) $safeArea < 0.7) {
            $warnings[] = 'Overlay safe area score basso per uso mobile.';
        }
        if (is_numeric($overlapRisk) && (float) $overlapRisk > 0.45) {
            $warnings[] = 'Overlay overlap risk alto: possibile copertura di volto/prodotto o crowding visivo.';
        }
        if (is_numeric($mobileReadability) && (float) $mobileReadability < 0.68) {
            $warnings[] = 'Overlay mobile readability sotto soglia premium.';
        }

        foreach ((array) ($overlayReview['warnings'] ?? []) as $warning) {
            $warnings[] = (string) $warning;
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $storyboardReview
     * @return array<int, string>
     */
    private function storyboardWarnings(ContentItem $item, array $meta, array $storyboardReview): array
    {
        if (!$this->isVideoFormat((string) $item->format)) {
            return [];
        }

        $warnings = [];
        if (!((bool) ($storyboardReview['present'] ?? false))) {
            $warnings[] = 'Per formato video/reel manca storyboard_meta con scene, voiceover e overlay temporizzati.';
            return $warnings;
        }

        if (!((bool) ($storyboardReview['hook_scene_present'] ?? false))) {
            $warnings[] = 'Storyboard presente ma senza hook iniziale esplicito.';
        }
        if (!((bool) ($storyboardReview['cta_scene_present'] ?? false))) {
            $warnings[] = 'Storyboard presente ma senza CTA finale esplicita.';
        }
        if (!((bool) ($storyboardReview['safe_areas_valid'] ?? true))) {
            $warnings[] = 'Storyboard con safe area non coerenti per hook o CTA.';
        }
        if (!((bool) ($storyboardReview['identity_safe'] ?? true))) {
            $warnings[] = 'Storyboard identity-first non sufficientemente protetto: overlay troppo vicino alla zona focale.';
        }
        if ((int) ($storyboardReview['overlay_segment_count'] ?? 0) < 2) {
            $warnings[] = 'Storyboard con pochi overlay segmentati: hook e payoff/CTA risultano deboli.';
        }
        if ((int) ($storyboardReview['voiceover_segment_count'] ?? 0) < max(1, ((int) ($storyboardReview['scene_count'] ?? 0)) - 1)) {
            $warnings[] = 'Storyboard con voiceover segmentato incompleto rispetto alle scene pianificate.';
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function contentStrategyValue(array $meta, string $path, mixed $default = null): mixed
    {
        $root = data_get($meta, $path);
        if ($root !== null && $root !== []) {
            return $root;
        }

        return data_get($meta, 'item_brain.' . $path, $default);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function hasContentStrategyRootPersistence(array $meta): bool
    {
        foreach (['hook_meta', 'authority_signals', 'trust_signals', 'viral_angle', 'content_structure_meta'] as $key) {
            if (!array_key_exists($key, $meta)) {
                return false;
            }
        }

        return true;
    }

    private function isVideoFormat(string $format): bool
    {
        return in_array(Str::lower(trim($format)), ['reel', 'story', 'video'], true);
    }
}
