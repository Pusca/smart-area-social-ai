<?php

namespace Tests\Unit;

use App\Services\Overlays\ContentOverlayEngine;
use Tests\TestCase;

class ContentOverlayEngineTest extends TestCase
{
    public function test_it_builds_video_overlay_templates_with_brand_preferences_and_cta(): void
    {
        $pack = app(ContentOverlayEngine::class)->build([
            'platform' => 'instagram',
            'format' => 'reel',
            'tenant_profile' => [
                'overlay_preferences' => [
                    'tone_preset' => 'premium',
                    'font_family' => 'georgia',
                    'fallback_font_family' => 'arial',
                    'preset' => 'premium_title_card',
                    'safe_area' => 'upper_third',
                    'auto_enabled' => true,
                ],
                'cta' => 'Prenota una call',
            ],
            'item_brain' => [
                'content_strategy_type' => 'authoritative',
                'narrative_angle' => 'Il criterio che cambia la lettura del servizio',
            ],
            'hook_meta' => [
                'main_hook' => 'La parte che tutti saltano e proprio quella che decide il risultato.',
                'authority_cue' => 'Framework usato sul campo con clienti reali.',
                'proof_or_trust_cue' => 'Caso reale, non teoria.',
            ],
            'content_structure_meta' => [
                'video_segments' => [
                    'development_3_8' => 'Mostra il passaggio critico che fa la differenza.',
                ],
            ],
            'ai_cta' => 'Scrivici per un confronto rapido',
            'video_duration_seconds_requested' => 18,
        ]);

        $this->assertSame('premium_title_card', data_get($pack, 'preset.key'));
        $this->assertSame('georgia', data_get($pack, 'brand_preferences.font_family'));
        $this->assertSame('auto', data_get($pack, 'mode'));
        $this->assertCount(3, (array) $pack['templates']);
        $this->assertSame('primary_hook', data_get($pack, 'templates.0.role'));
        $this->assertSame('development', data_get($pack, 'templates.1.role'));
        $this->assertSame('final_cta', data_get($pack, 'templates.2.role'));
        $this->assertNotSame('', (string) data_get($pack, 'overlay_brief'));
        $this->assertTrue((bool) data_get($pack, 'readability.overall_score') >= 0);
    }

    public function test_it_uses_manual_override_when_overlay_mode_is_manual(): void
    {
        $pack = app(ContentOverlayEngine::class)->build([
            'platform' => 'linkedin',
            'format' => 'post',
            'tenant_profile' => [
                'overlay_preferences' => [
                    'preset' => 'minimal_clean_stat',
                    'font_family' => 'segoe_ui',
                    'fallback_font_family' => 'arial',
                    'auto_enabled' => true,
                ],
            ],
            'overlay_settings' => [
                'mode' => 'manual',
                'preset' => 'editorial_quote_card',
                'manual_override' => [
                    'text' => 'Metodo chiaro, non rumore',
                    'secondary_text' => 'Più percezione premium, meno fuffa',
                    'font_family' => 'bahnschrift',
                    'fallback_font_family' => 'arial',
                    'font_weight' => '700',
                    'font_size_mode' => 'large',
                    'text_case' => 'sentence',
                    'alignment' => 'left',
                    'position' => 'center_left',
                    'safe_area' => 'center_safe',
                    'max_lines' => 2,
                    'color' => '#FFFFFF',
                    'stroke_color' => '#0F172A',
                    'shadow' => true,
                    'background_style' => 'dark_box',
                    'animation_style' => 'fade',
                    'emphasis_words' => ['Metodo'],
                ],
            ],
        ]);

        $this->assertSame('manual', data_get($pack, 'mode'));
        $this->assertSame('editorial_quote_card', data_get($pack, 'preset.key'));
        $this->assertSame('Metodo chiaro, non rumore', data_get($pack, 'templates.0.text'));
        $this->assertSame('bahnschrift', data_get($pack, 'templates.0.font_family'));
        $this->assertSame('center_safe', data_get($pack, 'templates.0.safe_area'));
    }

    public function test_it_maps_cta_mode_keys_to_human_readable_overlay_copy(): void
    {
        $pack = app(ContentOverlayEngine::class)->build([
            'platform' => 'instagram',
            'format' => 'reel',
            'tenant_profile' => [
                'overlay_preferences' => [
                    'preset' => 'modern_split_caption',
                    'font_family' => 'bahnschrift',
                    'fallback_font_family' => 'arial',
                    'auto_enabled' => true,
                ],
            ],
            'hook_meta' => [
                'main_hook' => 'Metodo chiaro, non rumore.',
                'cta_mode' => 'save_or_share',
            ],
            'video_duration_seconds_requested' => 12,
        ]);

        $ctaTemplate = collect((array) data_get($pack, 'templates', []))
            ->firstWhere('role', 'final_cta');

        $this->assertSame('Salva questo contenuto', data_get($ctaTemplate, 'text'));
    }
}
