<?php

namespace App\Services\AI;

use App\Models\ContentItem;
use App\Models\GenerationRun;

class PublishReadinessGate
{
    public function __construct(
        private readonly IdentityGuard $identityGuard
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $scorecard
     * @return array<string, mixed>
     */
    public function decideForContentItem(ContentItem $item, ?GenerationRun $run = null, ?array $scorecard = null): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $scorecard ??= is_array(data_get($meta, 'quality_scorecard')) ? (array) data_get($meta, 'quality_scorecard') : [];
        $identityValidation = $this->identityGuard->validateFinal($item, $run);
        $scoreStatus = (string) ($scorecard['publish_readiness_status'] ?? 'manual_review_required');
        $blockingReasons = array_values(array_filter(array_map('strval', (array) ($scorecard['blocking_reasons'] ?? []))));
        $warnings = array_values(array_filter(array_map('strval', (array) ($scorecard['warnings'] ?? []))));

        if ((string) ($identityValidation['status'] ?? '') === 'blocked') {
            return $this->payload(
                'blocked',
                array_values(array_unique(array_merge(
                    $blockingReasons,
                    (array) ($identityValidation['blocking_reasons'] ?? [])
                ))),
                $warnings,
                $identityValidation,
                ['identity_fidelity_hard_fail']
            );
        }

        if ((string) ($item->ai_status ?? '') !== 'done') {
            return $this->payload(
                'blocked',
                array_values(array_unique(array_merge($blockingReasons, ['Il contenuto non ha completato la generazione finale.']))),
                $warnings,
                $identityValidation,
                ['generation_not_completed']
            );
        }

        if ($scoreStatus === 'blocked') {
            if ($this->needsVisualRegeneration($scorecard)) {
                return $this->payload('regenerate_visual', $blockingReasons, $warnings, $identityValidation, ['visual_quality_or_identity']);
            }
            if ($this->needsAudioRegeneration($item, $scorecard)) {
                return $this->payload('regenerate_audio', $blockingReasons, $warnings, $identityValidation, ['audio_or_voice_issue']);
            }

            return $this->payload('regenerate_caption', $blockingReasons, $warnings, $identityValidation, ['copy_or_strategy_issue']);
        }

        if ($scoreStatus === 'manual_review_required') {
            return $this->payload('manual_review_required', $blockingReasons, $warnings, $identityValidation, ['manual_review']);
        }

        if ($scoreStatus === 'pass_with_warnings') {
            return $this->payload('pass_with_warnings', $blockingReasons, $warnings, $identityValidation, ['warnings_present']);
        }

        return $this->payload('pass', $blockingReasons, $warnings, $identityValidation, ['all_checks_passed']);
    }

    /**
     * @param  array<int, string>  $blockingReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $identityValidation
     * @param  array<int, string>  $reasons
     * @return array<string, mixed>
     */
    private function payload(
        string $decision,
        array $blockingReasons,
        array $warnings,
        array $identityValidation,
        array $reasons
    ): array {
        return [
            'version' => (string) config('social_manager.publish_gate.version', 'publish_gate_v1'),
            'checked_at' => now()->toDateTimeString(),
            'decision' => $decision,
            'blocking_reasons' => array_values(array_unique(array_filter($blockingReasons))),
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'identity_validation' => $identityValidation,
            'decision_reasons' => array_values(array_unique(array_filter($reasons))),
            'approvable' => in_array($decision, (array) config('social_manager.publish_gate.allow_statuses', ['pass', 'pass_with_warnings']), true),
        ];
    }

    /**
     * @param  array<string, mixed>  $scorecard
     */
    private function needsVisualRegeneration(array $scorecard): bool
    {
        return
            $this->lowScore($scorecard, 'overlay_readability_score')
            || $this->lowScore($scorecard, 'mobile_legibility_score')
            || $this->lowScore($scorecard, 'visual_identity_score')
            || $this->lowScore($scorecard, 'reference_match_score');
    }

    private function needsAudioRegeneration(ContentItem $item, array $scorecard): bool
    {
        $format = strtolower(trim((string) $item->format));

        return in_array($format, ['reel', 'story', 'video'], true)
            && $this->lowScore($scorecard, 'first_seconds_strength_score')
            && trim((string) ($item->ai_cta ?? '')) === '';
    }

    /**
     * @param  array<string, mixed>  $scorecard
     */
    private function lowScore(array $scorecard, string $key): bool
    {
        return is_numeric($scorecard[$key] ?? null) && (float) $scorecard[$key] < 0.5;
    }
}
