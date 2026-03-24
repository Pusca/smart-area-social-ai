<?php

namespace App\Services\Editorial;

use App\Models\TenantProfile;

class CreativeDirectionComposer
{
    /**
     * @param  array<string, int>  $assetReadiness
     * @return array<string, mixed>
     */
    public function compose(?TenantProfile $profile, array $assetReadiness = []): array
    {
        $industry = trim((string) ($profile?->industry ?? 'brand'));
        $industryLabel = $industry !== '' ? $industry : 'brand';
        $tone = trim((string) ($profile?->default_tone ?? 'professionale'));
        $target = trim((string) ($profile?->target ?? ''));
        $postsPerWeek = max(2, (int) ($profile?->default_posts_per_week ?: 5));
        $images = max(0, (int) ($assetReadiness['images'] ?? 0));
        $videos = max(0, (int) ($assetReadiness['videos'] ?? 0));
        $logos = max(0, (int) ($assetReadiness['logos'] ?? 0));
        $overlayPreferences = is_array($profile?->overlay_preferences) ? $profile->overlay_preferences : [];
        $fontPreset = trim((string) ($overlayPreferences['font_preset'] ?? $overlayPreferences['tone_preset'] ?? 'modern'));
        $hookIntensity = trim((string) ($overlayPreferences['preferred_hook_intensity'] ?? 'medium'));
        $trendAppetite = trim((string) ($overlayPreferences['trend_appetite'] ?? 'medium'));
        $guardrailLevel = trim((string) ($overlayPreferences['professionalism_guardrail_level'] ?? 'high'));

        $hasStrongVisualBase = ($images >= 6) || ($images >= 3 && $videos >= 1);
        $trendUsageMode = match ($trendAppetite) {
            'low' => 'concept_light_only',
            'high' => $hasStrongVisualBase ? 'adapt_selectively' : 'concept_light_only',
            default => $hasStrongVisualBase ? 'adapt_selectively' : 'concept_light_only',
        };
        $overlayPosition = $postsPerWeek >= 5 ? 'upper_left_or_upper_center' : 'upper_third';
        $headlineMaxWords = match ($hookIntensity) {
            'low' => 7,
            'high' => 5,
            default => 6,
        };
        $qualityBar = $target !== ''
            ? "Ogni contenuto deve sembrare costruito per {$target}, non per un pubblico generico."
            : 'Ogni contenuto deve sembrare costruito per un pubblico reale e specifico, non generico.';
        $bannedHookFragments = array_values(array_slice(array_filter(array_map(
            'strval',
            (array) config('content_strategy.guardrails.banned_hook_fragments', [])
        )), 0, 6));

        return [
            'version' => 'creative_direction_v1',
            'professional_direction' => [
                'positioning' => "Presidio {$industryLabel} con tono {$tone}, taglio concreto e credibilita editoriale.",
                'authority_mode' => 'credible_concrete_human',
                'quality_bar' => $qualityBar,
                'conversion_style' => 'CTA naturali, credibili e integrate nel racconto; niente pressione commerciale gratuita.',
                'differentiators' => [
                    'Dettagli reali prima di claim astratti.',
                    'Valore percepibile entro i primi secondi o nelle prime righe.',
                    'Stile da brand solido, non da creator improvvisato.',
                ],
                'avoid_patterns' => [
                    'Tono da agenzia o consulente che parla del cliente in terza persona.',
                    'Promesse gonfiate, numeri inventati o claim non verificabili.',
                    'Visual da brochure statica o corporate generica.',
                ],
            ],
            'trend_policy' => [
                'usage_mode' => $trendUsageMode,
                'appetite' => $trendAppetite,
                'goal' => 'Usare trend, hook e meccaniche social solo se migliorano attenzione e memorabilita restando coerenti col brand.',
                'brand_safe_rule' => 'Tratta ogni trend come linguaggio o struttura da tradurre, non come meme da copiare.',
                'allowed_mechanics' => [
                    'Hook rapido e leggibile nei primi secondi.',
                    'Pattern interrupt visivo o narrativo coerente al settore.',
                    'Comment trigger naturale e brand-safe.',
                    'Angolo trend adattato a scena, prodotto o persona reale del brand.',
                ],
                'disallowed_mechanics' => [
                    'Meme scollegati dal brand.',
                    'Bait aggressivo o promesse ingannevoli.',
                    'Trend che obbligano a snaturare persone, luoghi o prodotti reali.',
                    'Ironia fuori tono o audio virali non coerenti con il posizionamento.',
                ],
                'virality_focus' => [
                    'Hook chiaro subito.',
                    'Valore salvabile o condivisibile.',
                    'Chiusura che lascia un takeaway o un invito alla risposta.',
                ],
            ],
            'typography_system' => [
                'overlay_mode' => (bool) ($overlayPreferences['auto_enabled'] ?? true) ? 'rendered_overlay_system' : 'layout_safe_area_only',
                'headline_style' => 'short_punchy_italian',
                'max_words' => $headlineMaxWords,
                'safe_area' => (string) ($overlayPreferences['safe_area'] ?? $overlayPosition),
                'preferred_preset' => (string) ($overlayPreferences['preset'] ?? 'modern_split_caption'),
                'font_family' => (string) ($overlayPreferences['font_family'] ?? 'arial'),
                'fallback_font_family' => (string) ($overlayPreferences['fallback_font_family'] ?? 'segoe_ui'),
                'tone_preset' => $fontPreset,
                'font_preset' => $fontPreset,
                'text_density' => 'minimal',
                'do' => 'Lascia spazio pulito per eventuale headline o payoff breve, con contrasto e leggibilita mobile.',
                'dont' => 'Non affidare al modello testo lungo o lettering complesso dentro il visual generato.',
                'video_rule' => 'Per reel e stories prepara un hook tipografico breve e forte, ma il visual deve restare leggibile anche senza testo.',
            ],
            'continuity_rules' => [
                'identity_priority' => 'Preserva prima identita reali, poi prodotto e infine ambiente.',
                'reference_policy' => 'Se esistono reference reali, usale come ancora e varia solo styling, props, luce o micro-regia.',
                'allowed_variations' => [
                    'stagione o decorazioni leggere',
                    'styling e outfit coerenti',
                    'camera angle e crop',
                    'props o micro-contesto coerenti',
                ],
                'blocked_variations' => [
                    'cambio volto o lineamenti',
                    'layout inventato del luogo',
                    'redesign del prodotto',
                    'sostituzione arbitraria dei colori distintivi del brand',
                ],
                'identity_strength' => $hasStrongVisualBase ? 'high' : 'medium',
                'logo_presence' => $logos > 0 ? 'real_logo_available' : 'logo_missing',
            ],
            'content_strategy' => [
                'version' => (string) config('content_strategy.version', 'content_angle_engine_v1'),
                'priority' => 'Prima autorevolezza percepita, poi engagement: mai il contrario.',
                'professionalism_guardrail_level' => $guardrailLevel,
                'hook_policy' => [
                    'style' => $hookIntensity === 'low' ? 'measured_professional_stop_scroll' : 'professional_stop_scroll',
                    'preferred_intensity' => $hookIntensity,
                    'allowed_triggers' => ['pain', 'desire', 'curiosity'],
                    'disallowed_fragments' => $bannedHookFragments,
                    'rule' => 'Hook forti ma puliti: niente urla, bait aggressivo o promesse gonfiate.',
                ],
                'proof_policy' => [
                    'authority_first' => [
                        'dettagli operativi reali',
                        'criteri di scelta leggibili',
                        'segnali di esperienza concreta',
                    ],
                    'trust_first' => [
                        'scene reali del brand',
                        'proof cue osservabili',
                        'specificita invece di claim astratti',
                    ],
                ],
                'cta_policy' => [
                    'default_mode' => 'consultative_soft',
                    'rule' => 'CTA naturali e credibili, sempre integrate al racconto e mai manipolative.',
                ],
            ],
        ];
    }
}
