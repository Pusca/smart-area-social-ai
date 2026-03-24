<?php

namespace App\Services\Overlays;

use App\Models\ContentItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class ContentOverlayEngine
{
    public function __construct(
        private readonly ContentOverlayFontRegistry $fontRegistry,
        private readonly ContentOverlayReadabilityService $readability
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $tenantProfile = (array) ($context['tenant_profile'] ?? []);
        $strategy = (array) ($context['strategy'] ?? []);
        $hookMeta = (array) data_get($context, 'hook_meta', data_get($context, 'item_brain.hook_meta', []));
        $overlaySettings = (array) ($context['overlay_settings'] ?? []);
        $brandPreferences = $this->resolveBrandPreferences($tenantProfile, $strategy, $overlaySettings);
        $mode = $this->resolveMode($overlaySettings, $brandPreferences);
        $platform = $this->normalizePlatform((string) ($context['platform'] ?? data_get($context, 'item.platform', 'instagram')));
        $format = Str::lower(trim((string) ($context['format'] ?? data_get($context, 'item.format', 'post'))));
        $strategyType = trim((string) ($context['content_strategy_type'] ?? data_get($context, 'item_brain.content_strategy_type', data_get($context, 'content_strategy.strategy_type', 'educational'))));
        $storyboardMeta = (array) ($context['storyboard_meta'] ?? data_get($context, 'item_brain.storyboard_meta', []));
        $preset = $this->resolvePreset($brandPreferences, $overlaySettings, $platform, $format, $strategyType);
        $style = $this->resolveStyle($preset, $brandPreferences, $overlaySettings, $platform);

        $mainText = $this->resolveMainText($overlaySettings, $hookMeta, $context);
        $secondaryText = $this->resolveSecondaryText($overlaySettings, $hookMeta, $context);
        $ctaText = $this->resolveCtaText($context, $hookMeta);
        $emphasisWords = $this->resolveEmphasisWords($overlaySettings, $mainText);
        $durationMs = $this->resolveDurationMs($context, $format);
        $useStoryboardTemplates = $this->isVideoFormat($format)
            && !empty((array) data_get($storyboardMeta, 'scene_list', []));

        $templates = [];
        if ($mode !== 'off' && $mainText !== '' && !$useStoryboardTemplates) {
            $templates[] = new ContentOverlayTemplate(
                role: 'primary_hook',
                text: $mainText,
                secondaryText: $secondaryText,
                style: $style,
                timingStartMs: 0,
                timingEndMs: $this->introEndMs($format, $durationMs),
                emphasisWords: $emphasisWords
            );
        }

        if ($this->isVideoFormat($format) && $mode !== 'off' && $useStoryboardTemplates) {
            $templates = $this->buildStoryboardTemplates($storyboardMeta, $style, $hookMeta);
        } elseif ($this->isVideoFormat($format) && $mode !== 'off') {
            $developmentText = $this->shortenForOverlay(
                (string) data_get($hookMeta, 'video_3_8_development', data_get($context, 'content_structure_meta.video_segments.development_3_8', '')),
                46,
                2
            );
            if ($developmentText !== '') {
                $templates[] = new ContentOverlayTemplate(
                    role: 'development',
                    text: $developmentText,
                    secondaryText: '',
                    style: ContentOverlayStyle::fromArray(array_merge($style->toArray(), [
                        'font_size_mode' => $style->fontSizeMode === 'xl' ? 'large' : $style->fontSizeMode,
                        'position' => 'center_left',
                        'safe_area' => 'center_safe',
                    ])),
                    timingStartMs: (int) config('overlays.video_defaults.development_start_ms', 3000),
                    timingEndMs: min($durationMs, (int) config('overlays.video_defaults.development_end_ms', 8000)),
                    emphasisWords: []
                );
            }

            if ($ctaText !== '') {
                $templates[] = new ContentOverlayTemplate(
                    role: 'final_cta',
                    text: $ctaText,
                    secondaryText: $this->shortenForOverlay((string) data_get($hookMeta, 'proof_or_trust_cue', ''), 42, 1),
                    style: ContentOverlayStyle::fromArray(array_merge($style->toArray(), [
                        'position' => 'lower_center',
                        'safe_area' => 'lower_third',
                        'font_size_mode' => 'medium',
                        'background_style' => 'dark_box',
                        'animation_style' => 'fade',
                    ])),
                    timingStartMs: max(0, $durationMs - (int) config('overlays.video_defaults.outro_duration_ms', 2500)),
                    timingEndMs: $durationMs,
                    emphasisWords: []
                );
            }
        }

        $templateRows = array_map(fn (ContentOverlayTemplate $template) => $template->toArray(), $templates);
        $overlayBrief = $this->buildOverlayBrief($style, $templateRows, $format, $mode);
        $readability = $this->readability->evaluate([
            'templates' => $templateRows,
        ], null, [
            'platform' => $platform,
            'format' => $format,
        ]);

        return [
            'version' => (string) config('overlays.version', 'content_overlay_system_v1'),
            'mode' => $mode,
            'format' => $format,
            'platform' => $platform,
            'strategy_type' => $strategyType,
            'preset' => $preset->toArray(),
            'brand_preferences' => $brandPreferences,
            'templates' => $templateRows,
            'hook_text_initial' => $mainText,
            'caption_card_final' => $ctaText,
            'overlay_brief' => $overlayBrief,
            'readability' => $readability,
            'rendering' => [
                'status' => $mode === 'off' ? 'disabled' : 'planned',
                'apply_to_image' => true,
                'apply_to_video' => true,
                'mobile_priority' => true,
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
        return [
            'overlay_meta' => $pack,
            'item_brain' => [
                'overlay_meta' => $pack,
                'overlay_brief' => (string) ($pack['overlay_brief'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildForContentItem(ContentItem $item, array $context = []): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        return $this->build(array_replace_recursive([
            'platform' => (string) $item->platform,
            'format' => (string) $item->format,
            'item' => [
                'platform' => (string) $item->platform,
                'format' => (string) $item->format,
                'title' => (string) ($item->title ?? ''),
            ],
            'tenant_profile' => (array) data_get($meta, 'tenant_profile', []),
            'strategy' => (array) data_get($meta, 'strategy', []),
            'item_brain' => (array) data_get($meta, 'item_brain', []),
                'hook_meta' => (array) data_get($meta, 'hook_meta', data_get($meta, 'item_brain.hook_meta', [])),
                'overlay_settings' => (array) data_get($meta, 'overlay_settings', []),
                'storyboard_meta' => (array) data_get($meta, 'storyboard_meta', []),
                'content_structure_meta' => (array) data_get($meta, 'content_structure_meta', data_get($meta, 'item_brain.content_structure_meta', [])),
                'ai_cta' => (string) ($item->ai_cta ?? ''),
                'caption' => (string) ($item->ai_caption ?? $item->caption ?? ''),
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $tenantProfile
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $overlaySettings
     * @return array<string, mixed>
     */
    private function resolveBrandPreferences(array $tenantProfile, array $strategy, array $overlaySettings): array
    {
        $profilePrefs = (array) data_get($tenantProfile, 'overlay_preferences', []);
        $strategyTypography = (array) data_get($strategy, 'visual_system.typography', []);
        $preferences = array_merge([
            'tone_preset' => 'modern',
            'font_preset' => 'modern',
            'font_family' => 'arial',
            'fallback_font_family' => 'segoe_ui',
            'preset' => 'modern_split_caption',
            'safe_area' => 'upper_third',
            'auto_enabled' => true,
            'preferred_hook_intensity' => 'medium',
            'trend_appetite' => 'medium',
            'professionalism_guardrail_level' => 'high',
        ], $strategyTypography, $profilePrefs, Arr::only($overlaySettings, [
            'brand_font_family',
            'brand_fallback_font_family',
        ]));

        $tonePreset = trim((string) ($preferences['tone_preset'] ?? ''));
        $fontPreset = trim((string) ($preferences['font_preset'] ?? ''));
        if ($tonePreset === '' && $fontPreset !== '') {
            $preferences['tone_preset'] = $fontPreset;
        }
        if ($fontPreset === '' && $tonePreset !== '') {
            $preferences['font_preset'] = $tonePreset;
        }

        return $preferences;
    }

    /**
     * @param  array<string, mixed>  $overlaySettings
     * @param  array<string, mixed>  $brandPreferences
     */
    private function resolveMode(array $overlaySettings, array $brandPreferences): string
    {
        $mode = Str::lower(trim((string) ($overlaySettings['mode'] ?? config('overlays.default_mode', 'auto'))));
        if (in_array($mode, ['auto', 'manual', 'off'], true)) {
            return $mode;
        }

        return (bool) ($brandPreferences['auto_enabled'] ?? true) ? 'auto' : 'off';
    }

    /**
     * @param  array<string, mixed>  $brandPreferences
     * @param  array<string, mixed>  $overlaySettings
     */
    private function resolvePreset(array $brandPreferences, array $overlaySettings, string $platform, string $format, string $strategyType): ContentOverlayPreset
    {
        $presetKey = trim((string) ($overlaySettings['preset'] ?? $brandPreferences['preset'] ?? ''));
        $presets = (array) config('overlays.presets', []);

        if ($presetKey === '' || !isset($presets[$presetKey])) {
            if ($this->isVideoFormat($format)) {
                $presetKey = in_array($strategyType, ['trend-aware', 'emotional-relatable'], true)
                    ? 'bold_hook_banner'
                    : 'modern_split_caption';
            } elseif (in_array($strategyType, ['authoritative', 'educational'], true)) {
                $presetKey = 'premium_title_card';
            } elseif (in_array($strategyType, ['conversion', 'social-proof'], true)) {
                $presetKey = 'modern_split_caption';
            } else {
                $presetKey = data_get(config('overlays.tone_presets'), data_get($brandPreferences, 'tone_preset', 'modern') . '.preset', 'minimal_clean_stat');
            }
        }

        return ContentOverlayPreset::fromArray($presetKey, (array) ($presets[$presetKey] ?? []));
    }

    /**
     * @param  array<string, mixed>  $brandPreferences
     * @param  array<string, mixed>  $overlaySettings
     */
    private function resolveStyle(ContentOverlayPreset $preset, array $brandPreferences, array $overlaySettings, string $platform): ContentOverlayStyle
    {
        $platformRule = (array) data_get(config('overlays.platform_rules', []), $platform, []);
        $manual = (array) ($overlaySettings['manual_override'] ?? []);
        $style = array_merge(
            $preset->style->toArray(),
            $platformRule,
            [
                'font_family' => (string) ($manual['font_family'] ?? $brandPreferences['font_family'] ?? $preset->style->fontFamily),
                'fallback_font_family' => (string) ($manual['fallback_font_family'] ?? $brandPreferences['fallback_font_family'] ?? $this->fontRegistry->resolveFallbackFamily($preset->style->fontFamily)),
                'safe_area' => (string) ($manual['safe_area'] ?? $brandPreferences['safe_area'] ?? $preset->style->safeArea),
            ],
            Arr::only($manual, [
                'font_weight',
                'font_size_mode',
                'text_case',
                'alignment',
                'position',
                'safe_area',
                'max_lines',
                'color',
                'stroke_color',
                'shadow',
                'background_style',
                'animation_style',
            ])
        );

        return ContentOverlayStyle::fromArray($style);
    }

    /**
     * @param  array<string, mixed>  $overlaySettings
     * @param  array<string, mixed>  $hookMeta
     * @param  array<string, mixed>  $context
     */
    private function resolveMainText(array $overlaySettings, array $hookMeta, array $context): string
    {
        $manual = trim((string) data_get($overlaySettings, 'manual_override.text', ''));
        if ($manual !== '') {
            return $this->shortenForOverlay($manual, 58, (int) data_get($overlaySettings, 'manual_override.max_lines', 2));
        }

        $candidates = [
            (string) ($hookMeta['main_hook'] ?? ''),
            (string) data_get($context, 'content_strategy.main_hook', ''),
            (string) data_get($context, 'item_brain.narrative_angle', ''),
            (string) data_get($context, 'item.title', ''),
            (string) ($context['caption'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $this->shortenForOverlay($candidate, 58, 2);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $overlaySettings
     * @param  array<string, mixed>  $hookMeta
     * @param  array<string, mixed>  $context
     */
    private function resolveSecondaryText(array $overlaySettings, array $hookMeta, array $context): string
    {
        $manual = trim((string) data_get($overlaySettings, 'manual_override.secondary_text', ''));
        if ($manual !== '') {
            return $this->shortenForOverlay($manual, 66, 2);
        }

        $candidates = [
            (string) ($hookMeta['authority_cue'] ?? ''),
            (string) ($hookMeta['narrative_angle'] ?? ''),
            (string) data_get($context, 'item_brain.narrative_angle', ''),
            (string) data_get($context, 'hook_meta.proof_or_trust_cue', ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $this->shortenForOverlay($candidate, 66, 2);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $storyboardMeta
     * @param  array<string, mixed>  $hookMeta
     * @return array<int, ContentOverlayTemplate>
     */
    private function buildStoryboardTemplates(array $storyboardMeta, ContentOverlayStyle $style, array $hookMeta): array
    {
        $templates = [];

        foreach ((array) data_get($storyboardMeta, 'scene_list', []) as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $payload = (array) ($scene['text_overlay'] ?? []);
            $text = trim((string) ($payload['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $sceneType = Str::lower(trim((string) ($scene['scene_type'] ?? 'development')));
            $overrides = array_merge($style->toArray(), [
                'position' => (string) ($payload['position'] ?? $style->position),
                'safe_area' => (string) ($payload['safe_area'] ?? $style->safeArea),
                'font_size_mode' => (string) ($payload['font_size_mode'] ?? $style->fontSizeMode),
                'background_style' => (string) ($payload['background_style'] ?? $style->backgroundStyle),
                'animation_style' => (string) ($payload['animation_style'] ?? $style->animationStyle),
                'max_lines' => max(1, (int) ($payload['max_lines'] ?? $style->maxLines)),
            ]);

            if ($sceneType === 'cta') {
                $overrides['position'] = (string) ($payload['position'] ?? 'lower_center');
                $overrides['safe_area'] = (string) ($payload['safe_area'] ?? 'lower_third');
                $overrides['font_size_mode'] = (string) ($payload['font_size_mode'] ?? 'medium');
            }

            $templates[] = new ContentOverlayTemplate(
                role: (string) ($payload['role'] ?? $sceneType),
                text: $text,
                secondaryText: $this->storyboardTemplateSecondaryText($scene, $payload, $hookMeta),
                style: ContentOverlayStyle::fromArray($overrides),
                timingStartMs: max(0, (int) ($payload['timing_start_ms'] ?? data_get($scene, 'timing_window.start_ms', 0))),
                timingEndMs: max(0, (int) ($payload['timing_end_ms'] ?? data_get($scene, 'timing_window.end_ms', 0))),
                emphasisWords: array_values(array_filter(array_map('strval', (array) ($payload['emphasis_words'] ?? []))))
            );
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $hookMeta
     */
    private function storyboardTemplateSecondaryText(array $scene, array $payload, array $hookMeta): string
    {
        $secondary = trim((string) ($payload['secondary_text'] ?? ''));
        if ($secondary !== '') {
            return $this->shortenForOverlay($secondary, 54, 2);
        }

        $sceneType = Str::lower(trim((string) ($scene['scene_type'] ?? 'development')));
        $candidate = match ($sceneType) {
            'hook' => trim((string) ($hookMeta['authority_cue'] ?? '')),
            'payoff', 'cta' => trim((string) ($hookMeta['proof_or_trust_cue'] ?? '')),
            default => trim((string) ($hookMeta['audience_trigger'] ?? '')),
        };

        return $candidate !== '' ? $this->shortenForOverlay($candidate, 54, 2) : '';
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $hookMeta
     */
    private function resolveCtaText(array $context, array $hookMeta): string
    {
        $candidates = [
            trim((string) data_get($context, 'overlay_settings.manual_override.cta_text', '')),
            trim((string) ($context['ai_cta'] ?? '')),
            trim((string) data_get($context, 'content_structure_meta.video_segments.cta_ending', '')),
            $this->resolveCtaModeFallback(trim((string) ($hookMeta['cta_mode'] ?? ''))),
            trim((string) data_get($context, 'tenant_profile.cta', '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            return $this->shortenForOverlay($candidate, 44, 2);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $overlaySettings
     * @return array<int, string>
     */
    private function resolveEmphasisWords(array $overlaySettings, string $mainText): array
    {
        $manual = (array) data_get($overlaySettings, 'manual_override.emphasis_words', []);
        if ($manual !== []) {
            return array_values(array_slice(array_filter(array_map('strval', $manual)), 0, 4));
        }

        $parts = preg_split('/[\s,.;:!?]+/u', $mainText) ?: [];
        $parts = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $parts
        ), fn ($value) => mb_strlen($value, 'UTF-8') >= 5));

        return array_values(array_slice(array_unique($parts), 0, 2));
    }

    private function resolveCtaModeFallback(string $ctaMode): string
    {
        return match (Str::lower(trim($ctaMode))) {
            'consultative_soft' => 'Scrivici per un confronto rapido',
            'save_or_share' => 'Salva questo contenuto',
            'comment_with_point_of_view' => 'Dimmi come la vedi nei commenti',
            'dm_or_click_soft' => 'Scrivici per approfondire',
            'reply_with_story' => 'Raccontaci la tua esperienza',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveDurationMs(array $context, string $format): int
    {
        $duration = (int) (
            $context['duration_ms']
            ?? data_get($context, 'video_duration_ms')
            ?? ((int) data_get($context, 'video_generation.target_total_seconds', 0) * 1000)
            ?? ((int) data_get($context, 'video_duration_seconds_requested', 0) * 1000)
        );

        if ($duration > 0) {
            return $duration;
        }

        return $this->isVideoFormat($format) ? 15000 : 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $templateRows
     */
    private function buildOverlayBrief(ContentOverlayStyle $style, array $templateRows, string $format, string $mode): string
    {
        if ($mode === 'off' || $templateRows === []) {
            return 'Nessun overlay tipografico richiesto.';
        }

        $maxWords = max(3, min(8, (int) ceil(mb_strlen((string) ($templateRows[0]['text'] ?? ''), 'UTF-8') / 10)));

        return trim(sprintf(
            'Overlay tipografico %s, safe area %s, posizione %s, max %d linee, circa %d parole forti, alta leggibilita mobile e spazio pulito nel visual.',
            $this->isVideoFormat($format) ? 'timing-based per video' : 'statico per immagine',
            $style->safeArea,
            $style->position,
            $style->maxLines,
            $maxWords
        ));
    }

    private function introEndMs(string $format, int $durationMs): int
    {
        if (!$this->isVideoFormat($format)) {
            return 0;
        }

        return min($durationMs, (int) config('overlays.video_defaults.intro_duration_ms', 3000));
    }

    private function shortenForOverlay(string $text, int $maxChars, int $maxLines): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $text)) ?? '');
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[!?]{2,}/u', '!', $normalized) ?? $normalized;
        $normalized = Str::of($normalized)->trim()->toString();

        if (mb_strlen($normalized, 'UTF-8') > $maxChars) {
            $normalized = Str::limit($normalized, $maxChars, '');
            $normalized = rtrim($normalized, ",;:- \t\n\r\0\x0B");
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];
        if (count($words) > ($maxLines * 5)) {
            $words = array_slice($words, 0, $maxLines * 5);
        }

        return trim(implode(' ', $words));
    }

    private function isVideoFormat(string $format): bool
    {
        return in_array(Str::lower(trim($format)), ['reel', 'story', 'video'], true);
    }

    private function normalizePlatform(string $platform): string
    {
        $parts = preg_split('/[\s,;|]+/', Str::lower(trim($platform))) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));

        return $parts[0] ?? 'instagram';
    }
}
