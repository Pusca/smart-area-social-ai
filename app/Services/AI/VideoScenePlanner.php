<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

class VideoScenePlanner
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $format = Str::lower(trim((string) ($context['format'] ?? data_get($context, 'item.format', 'post'))));
        if (!$this->isVideoFormat($format)) {
            return [];
        }

        $platform = Str::lower(trim((string) ($context['platform'] ?? data_get($context, 'item.platform', 'instagram'))));
        $hookMeta = (array) data_get($context, 'hook_meta', data_get($context, 'item_brain.hook_meta', []));
        $contentStructureMeta = (array) data_get($context, 'content_structure_meta', data_get($context, 'item_brain.content_structure_meta', []));
        $videoSegments = (array) data_get($contentStructureMeta, 'video_segments', []);
        $blueprint = is_array($context['reel_blueprint'] ?? null) ? (array) $context['reel_blueprint'] : [];
        $shots = $this->resolveShots($blueprint, $hookMeta, $videoSegments, $context);
        if ($shots === []) {
            return [];
        }

        $totalDurationMs = $this->resolveTotalDurationMs($format, $context);
        $ctaText = $this->resolveCtaText($context, $hookMeta);
        $identityFirst = $this->isIdentityFirst($context);
        $timings = $this->buildTimingPlan(count($shots), $totalDurationMs, $ctaText !== '');
        $voiceoverSegments = $this->buildVoiceoverSegments($timings, $context, $shots, $ctaText);
        $continuity = $this->buildContinuityRequirements($context, $blueprint, $identityFirst);

        $scenes = [];
        foreach ($timings as $index => $timing) {
            $sceneType = (string) ($timing['scene_type'] ?? 'development');
            $shot = $timing['shot_index'] !== null
                ? (array) ($shots[(int) $timing['shot_index']] ?? [])
                : [];
            $overlayPayload = $this->buildTextOverlayPayload(
                sceneType: $sceneType,
                timing: $timing,
                hookMeta: $hookMeta,
                videoSegments: $videoSegments,
                context: $context,
                shot: $shot,
                ctaText: $ctaText,
                identityFirst: $identityFirst
            );

            $voiceoverSegment = trim((string) ($voiceoverSegments[$index] ?? ''));
            $shotObjective = $this->sceneShotObjective($sceneType, $shot, $videoSegments, $ctaText);
            $visualIntent = $this->sceneVisualIntent($sceneType, $shot, $context);

            $ctaRole = $sceneType === 'cta'
                ? 'final_cta'
                : ($sceneType === 'payoff' && $ctaText !== '' ? 'soft_close' : 'none');

            $scenes[] = [
                'scene_index' => $index + 1,
                'scene_type' => $sceneType,
                'duration_target' => (int) ($timing['duration_target_ms'] ?? 0),
                'timing_window' => [
                    'start_ms' => (int) ($timing['start_ms'] ?? 0),
                    'end_ms' => (int) ($timing['end_ms'] ?? 0),
                ],
                'shot_objective' => $shotObjective,
                'visual_intent' => $visualIntent,
                'voiceover_segment' => $voiceoverSegment,
                'overlay_text_segment' => trim((string) ($overlayPayload['text'] ?? '')),
                'visual_prompt_fragment' => $this->sceneVisualPromptFragment($sceneType, $shot, $context),
                'continuity_requirements' => $continuity,
                'text_overlay' => $overlayPayload,
                'cta_role' => $ctaRole,
                'CTA_role' => $ctaRole,
            ];
        }

        $speechPlanSegments = array_map(function (array $scene): array {
            return [
                'scene_index' => (int) ($scene['scene_index'] ?? 0),
                'scene_type' => (string) ($scene['scene_type'] ?? ''),
                'text' => trim((string) ($scene['voiceover_segment'] ?? '')),
                'timing_start_ms' => (int) data_get($scene, 'timing_window.start_ms', 0),
                'timing_end_ms' => (int) data_get($scene, 'timing_window.end_ms', 0),
            ];
        }, $scenes);

        return [
            'version' => (string) config('video_storyboard.version', 'video_scene_planner_v1'),
            'platform' => $platform,
            'format' => $format,
            'identity_first' => $identityFirst,
            'scene_count' => count($scenes),
            'total_duration_ms' => $totalDurationMs,
            'hook_scene_present' => collect($scenes)->contains(fn (array $scene) => ($scene['scene_type'] ?? '') === 'hook'),
            'cta_scene_present' => collect($scenes)->contains(fn (array $scene) => ($scene['scene_type'] ?? '') === 'cta'),
            'continuity_lock' => trim((string) ($blueprint['continuity_lock'] ?? '')),
            'scene_list' => $scenes,
            'speech_plan' => [
                'full_text' => trim(implode(' ', array_values(array_filter(array_map(
                    fn (array $segment) => trim((string) ($segment['text'] ?? '')),
                    $speechPlanSegments
                ))))),
                'segments' => $speechPlanSegments,
            ],
            'safe_area_policy' => [
                'profile' => $identityFirst ? 'identity_first' : 'default',
                'mobile_priority' => true,
                'identity_protection' => $identityFirst,
            ],
            'provider_bridge' => [
                'prompt_summary' => $this->providerPromptSummary($scenes, $identityFirst),
                'overlay_safe' => true,
            ],
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array<string, mixed>
     */
    public function toMetaFragment(array $pack): array
    {
        if ($pack === []) {
            return [];
        }

        return [
            'storyboard_meta' => $pack,
            'item_brain' => [
                'storyboard_meta' => $pack,
                'storyboard_brief' => (string) data_get($pack, 'provider_bridge.prompt_summary', ''),
            ],
        ];
    }

    private function isVideoFormat(string $format): bool
    {
        return in_array($format, ['reel', 'video', 'story'], true);
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $hookMeta
     * @param  array<string, mixed>  $videoSegments
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function resolveShots(array $blueprint, array $hookMeta, array $videoSegments, array $context): array
    {
        $shots = array_values(array_filter(
            (array) ($blueprint['shots'] ?? []),
            fn ($shot) => is_array($shot)
        ));

        if ($shots !== []) {
            return $shots;
        }

        $topic = trim((string) data_get($context, 'item_brain.narrative_angle', data_get($context, 'item.title', data_get($context, 'caption', 'il tema del contenuto'))));
        $mainSubject = trim((string) data_get($context, 'asset_identity.slots.presenter.name', ''))
            ?: trim((string) data_get($context, 'asset_identity.slots.product.name', ''))
            ?: 'il soggetto principale del brand';

        return [
            [
                'order' => 1,
                'purpose' => trim((string) ($videoSegments['hook_0_3'] ?? $hookMeta['main_hook'] ?? 'hook iniziale')),
                'subject' => $mainSubject,
                'camera' => 'medium shot verticale',
                'motion' => 'push-in leggero',
            ],
            [
                'order' => 2,
                'purpose' => trim((string) ($videoSegments['development_3_8'] ?? "sviluppo leggibile su {$topic}")),
                'subject' => $mainSubject,
                'camera' => 'angolazione coerente ma distinta',
                'motion' => 'tracking morbido',
            ],
            [
                'order' => 3,
                'purpose' => trim((string) ($videoSegments['payoff_reveal'] ?? "payoff finale su {$topic}")),
                'subject' => $mainSubject,
                'camera' => 'close medium premium',
                'motion' => 'movimento conclusivo',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveTotalDurationMs(string $format, array $context): int
    {
        $candidates = [
            (int) ($context['target_total_seconds'] ?? 0),
            (int) ($context['requested_total_seconds'] ?? 0),
            (int) ($context['video_duration_seconds_requested'] ?? 0),
            (int) data_get($context, 'video_generation.target_total_seconds', 0),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                return max(4000, $candidate * 1000);
            }
        }

        return (int) data_get(config('video_storyboard.duration_defaults_ms', []), $format, 16000);
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    private function buildTimingPlan(int $shotCount, int $totalDurationMs, bool $withCta): array
    {
        $hookMin = (int) config('video_storyboard.timing.hook_min_ms', 2200);
        $hookMax = (int) config('video_storyboard.timing.hook_max_ms', 3000);
        $ctaMin = (int) config('video_storyboard.timing.cta_min_ms', 1800);
        $ctaMax = (int) config('video_storyboard.timing.cta_max_ms', 2800);
        $minScene = (int) config('video_storyboard.timing.min_scene_ms', 1200);

        $shotCount = max(1, $shotCount);
        $hookDuration = max($hookMin, min($hookMax, (int) round($totalDurationMs * 0.18)));
        $ctaDuration = $withCta ? max($ctaMin, min($ctaMax, (int) round($totalDurationMs * 0.14))) : 0;
        $ctaStart = $withCta ? max($hookDuration + $minScene, $totalDurationMs - $ctaDuration) : $totalDurationMs;
        $middleTotal = max($minScene, $ctaStart - $hookDuration);

        $plan = [];
        $cursor = 0;
        $plan[] = [
            'scene_type' => 'hook',
            'shot_index' => 0,
            'start_ms' => 0,
            'end_ms' => $hookDuration,
            'duration_target_ms' => $hookDuration,
        ];
        $cursor = $hookDuration;

        $remainingShotCount = max(0, $shotCount - 1);
        if ($remainingShotCount > 0) {
            $slotMs = (int) floor($middleTotal / $remainingShotCount);
            for ($index = 1; $index < $shotCount; $index++) {
                $isLastShot = $index === ($shotCount - 1);
                $end = $isLastShot ? $ctaStart : min($ctaStart, $cursor + max($minScene, $slotMs));
                if ($end <= $cursor) {
                    $end = min($ctaStart, $cursor + $minScene);
                }

                $plan[] = [
                    'scene_type' => $isLastShot ? 'payoff' : 'development',
                    'shot_index' => $index,
                    'start_ms' => $cursor,
                    'end_ms' => $end,
                    'duration_target_ms' => max($minScene, $end - $cursor),
                ];
                $cursor = $end;
            }
        }

        if ($withCta) {
            $plan[] = [
                'scene_type' => 'cta',
                'shot_index' => null,
                'start_ms' => $ctaStart,
                'end_ms' => $totalDurationMs,
                'duration_target_ms' => max($ctaMin, $totalDurationMs - $ctaStart),
            ];
        } elseif (!empty($plan)) {
            $last = count($plan) - 1;
            $plan[$last]['end_ms'] = $totalDurationMs;
            $plan[$last]['duration_target_ms'] = max($minScene, $totalDurationMs - (int) $plan[$last]['start_ms']);
        }

        return $plan;
    }

    /**
     * @param  array<int, array<string, int|string|null>>  $timings
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $shots
     * @return array<int, string>
     */
    private function buildVoiceoverSegments(array $timings, array $context, array $shots, string $ctaText): array
    {
        $sentences = $this->splitSentences((string) ($context['video_voiceover'] ?? data_get($context, 'storyboard_meta.speech_plan.full_text', '')));
        $segments = [];

        foreach ($timings as $index => $timing) {
            $sceneType = (string) ($timing['scene_type'] ?? 'development');
            $sentence = trim((string) ($sentences[$index] ?? ''));
            if ($sentence !== '') {
                $segments[] = $sentence;
                continue;
            }

            $shot = $timing['shot_index'] !== null
                ? (array) ($shots[(int) $timing['shot_index']] ?? [])
                : [];

            $fallback = match ($sceneType) {
                'hook' => trim((string) ($shot['purpose'] ?? data_get($context, 'hook_meta.main_hook', 'Apri subito sul punto che conta.'))),
                'payoff' => trim((string) ($shot['purpose'] ?? data_get($context, 'content_structure_meta.video_segments.payoff_reveal', 'Chiudi sul dettaglio che fa capire il valore.'))),
                'cta' => $ctaText,
                default => trim((string) ($shot['purpose'] ?? data_get($context, 'content_structure_meta.video_segments.development_3_8', 'Sviluppa la scena in modo chiaro e leggibile.'))),
            };

            $segments[] = Str::limit($fallback, 120, '');
        }

        return $segments;
    }

    /**
     * @return array<int, string>
     */
    private function splitSentences(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($segment) => trim((string) $segment),
            preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: []
        )));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $blueprint
     * @return array<int, string>
     */
    private function buildContinuityRequirements(array $context, array $blueprint, bool $identityFirst): array
    {
        $requirements = $identityFirst
            ? (array) config('video_storyboard.continuity_requirements.identity_first', [])
            : (array) config('video_storyboard.continuity_requirements.default', []);

        $continuityLock = trim((string) ($blueprint['continuity_lock'] ?? ''));
        if ($continuityLock !== '') {
            array_unshift($requirements, $continuityLock);
        }

        $assetIdentityHint = trim((string) data_get($context, 'asset_identity.identity_summary', ''));
        if ($assetIdentityHint !== '') {
            $requirements[] = Str::limit($assetIdentityHint, 180, '');
        }

        return array_values(array_unique(array_filter(array_map('strval', $requirements))));
    }

    /**
     * @param  array<string, int|string|null>  $timing
     * @param  array<string, mixed>  $hookMeta
     * @param  array<string, mixed>  $videoSegments
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>
     */
    private function buildTextOverlayPayload(
        string $sceneType,
        array $timing,
        array $hookMeta,
        array $videoSegments,
        array $context,
        array $shot,
        string $ctaText,
        bool $identityFirst
    ): array {
        $profile = (array) data_get(
            config('video_storyboard.safe_area_profiles', []),
            ($identityFirst ? 'identity_first' : 'default') . '.' . $sceneType,
            []
        );
        $pattern = (array) data_get(config('video_storyboard.scene_patterns', []), $sceneType, []);

        $text = match ($sceneType) {
            'hook' => $this->flattenTextCandidate($hookMeta['main_hook'] ?? $videoSegments['hook_0_3'] ?? $shot['purpose'] ?? ''),
            'payoff' => $this->flattenTextCandidate($videoSegments['payoff_reveal'] ?? $shot['purpose'] ?? ''),
            'cta' => $ctaText,
            default => $this->flattenTextCandidate($videoSegments['development_3_8'] ?? $shot['purpose'] ?? ''),
        };

        $secondaryText = match ($sceneType) {
            'hook' => $this->flattenTextCandidate($hookMeta['authority_cue'] ?? ''),
            'payoff' => $this->flattenTextCandidate($hookMeta['proof_or_trust_cue'] ?? ''),
            'cta' => $this->flattenTextCandidate($hookMeta['proof_or_trust_cue'] ?? ''),
            default => $this->flattenTextCandidate($hookMeta['audience_trigger'] ?? ''),
        };

        return [
            'role' => $sceneType === 'cta' ? 'final_cta' : $sceneType,
            'text' => $this->shortText($text, $sceneType === 'hook' ? 58 : 52),
            'secondary_text' => $this->shortText($secondaryText, 54),
            'position' => (string) ($profile['position'] ?? $pattern['position'] ?? 'upper_left'),
            'safe_area' => (string) ($profile['safe_area'] ?? $pattern['safe_area'] ?? 'upper_third'),
            'font_size_mode' => (string) ($pattern['font_size_mode'] ?? 'large'),
            'background_style' => (string) ($pattern['background_style'] ?? 'dark_box'),
            'animation_style' => (string) ($pattern['animation_style'] ?? 'fade'),
            'max_lines' => max(1, (int) ($pattern['max_lines'] ?? 2)),
            'timing_start_ms' => (int) ($timing['start_ms'] ?? 0),
            'timing_end_ms' => (int) ($timing['end_ms'] ?? 0),
            'avoid_regions' => array_values(array_filter(array_map('strval', (array) ($profile['avoid_regions'] ?? [])))),
            'mobile_priority' => true,
            'identity_safe' => $identityFirst,
        ];
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $videoSegments
     */
    private function sceneShotObjective(string $sceneType, array $shot, array $videoSegments, string $ctaText): string
    {
        return match ($sceneType) {
            'hook' => trim((string) ($shot['purpose'] ?? $videoSegments['hook_0_3'] ?? 'hook iniziale forte')),
            'payoff' => trim((string) ($shot['purpose'] ?? $videoSegments['payoff_reveal'] ?? 'payoff finale leggibile')),
            'cta' => $ctaText !== '' ? $ctaText : 'chiusura finale con CTA morbida',
            default => trim((string) ($shot['purpose'] ?? $videoSegments['development_3_8'] ?? 'sviluppo chiaro della scena')),
        };
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $context
     */
    private function sceneVisualIntent(string $sceneType, array $shot, array $context): string
    {
        $subject = trim((string) ($shot['subject'] ?? ''));
        $camera = trim((string) ($shot['camera'] ?? ''));
        $motion = trim((string) ($shot['motion'] ?? ''));
        $identityHint = trim((string) data_get($context, 'item_brain.continuity_brief', ''));

        $parts = array_filter([
            $sceneType === 'hook' ? 'Apri con un frame stop-scroll e subito leggibile.' : null,
            $subject !== '' ? "Soggetto: {$subject}." : null,
            $camera !== '' ? "Camera: {$camera}." : null,
            $motion !== '' ? "Movimento: {$motion}." : null,
            $identityHint !== '' ? "Vincolo: {$identityHint}." : null,
        ], fn ($part) => is_string($part) && trim($part) !== '');

        return Str::limit(trim(implode(' ', $parts)), 220, '');
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $context
     */
    private function sceneVisualPromptFragment(string $sceneType, array $shot, array $context): string
    {
        $parts = array_filter([
            trim((string) ($shot['subject'] ?? '')),
            trim((string) ($shot['camera'] ?? '')),
            trim((string) ($shot['motion'] ?? '')),
            $sceneType === 'cta' ? 'chiusura pulita con spazio per CTA finale' : null,
            trim((string) data_get($context, 'storyboard_meta.provider_bridge.prompt_summary', '')),
        ], fn ($part) => is_string($part) && trim($part) !== '');

        return Str::limit(implode(', ', $parts), 220, '');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $hookMeta
     */
    private function resolveCtaText(array $context, array $hookMeta): string
    {
        $candidates = [
            trim((string) ($context['ai_cta'] ?? '')),
            trim((string) data_get($context, 'tenant_profile.cta', '')),
            trim((string) data_get($context, 'item_brain.content_structure_meta.video_segments.cta_ending', '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return Str::limit($candidate, 80, '');
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isIdentityFirst(array $context): bool
    {
        if (!empty((array) data_get($context, 'asset_identity.locked_elements', []))) {
            return true;
        }

        foreach ((array) data_get($context, 'asset_variables.resolved', []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = Str::lower(trim((string) ($row['kind'] ?? '')));
            if (in_array($kind, ['person', 'product', 'vehicle'], true)) {
                return true;
            }
        }

        return false;
    }

    private function shortText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        return Str::limit($text, $limit, '');
    }

    private function flattenTextCandidate(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($value, function ($item) use (&$parts): void {
            if (is_string($item) || is_numeric($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        });

        return trim(implode(' ', array_unique($parts)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    private function providerPromptSummary(array $scenes, bool $identityFirst): string
    {
        $parts = [];
        foreach ($scenes as $scene) {
            $parts[] = sprintf(
                'Scene %d %s %d-%dms: %s. Overlay safe area %s, no text baked into video.',
                (int) ($scene['scene_index'] ?? 0),
                (string) ($scene['scene_type'] ?? 'scene'),
                (int) data_get($scene, 'timing_window.start_ms', 0),
                (int) data_get($scene, 'timing_window.end_ms', 0),
                trim((string) ($scene['shot_objective'] ?? '')),
                (string) data_get($scene, 'text_overlay.safe_area', 'upper_third')
            );
        }

        if ($identityFirst) {
            $parts[] = 'Identity-first rule: keep face, product and key brand elements unobstructed in the focal zone.';
        }

        return Str::limit(trim(implode(' ', $parts)), 780, '');
    }
}
