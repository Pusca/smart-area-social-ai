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

        $scoreSources['brand_voice_score'] = $brandVoiceSource;
        $scoreSources['caption_quality_score'] = $captionQualitySource;
        $scoreSources['cta_compliance_score'] = $ctaSource;
        $scoreSources['reference_match_score'] = $referenceSource;
        $scoreSources['visual_identity_score'] = $visualIdentitySource;
        $scoreSources['realism_score'] = $realismSource;

        $warnings = array_merge($warnings, $brandVoiceWarnings, $captionWarnings, $ctaWarnings, $referenceWarnings, $visualWarnings, $realismWarnings);
        $blockingReasons = array_merge($blockingReasons, $referenceBlocks);

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
            'publish_readiness_status' => $status,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'blocking_reasons' => array_values(array_unique(array_filter($blockingReasons))),
            'score_sources' => $scoreSources,
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

    private function normalizeScore(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(max(0.0, min(1.0, $value)), 4);
    }
}
