<?php

namespace Tests\Unit;

use App\Services\AI\VideoScenePlanner;
use Tests\TestCase;

class VideoScenePlannerTest extends TestCase
{
    public function test_it_builds_a_mobile_first_short_reel_storyboard_with_hook_and_cta(): void
    {
        $pack = app(VideoScenePlanner::class)->build([
            'platform' => 'instagram',
            'format' => 'reel',
            'requested_total_seconds' => 8,
            'video_voiceover' => 'Apri subito sul punto che conta. Poi fai vedere il dettaglio che cambia la percezione. Chiudi con un invito morbido.',
            'hook_meta' => [
                'main_hook' => 'Il dettaglio che cambia tutto.',
                'authority_cue' => 'Visto sul campo.',
                'proof_or_trust_cue' => 'Scenario reale.',
            ],
            'content_structure_meta' => [
                'video_segments' => [
                    'hook_0_3' => 'Apri con il contrasto giusto.',
                    'development_3_8' => 'Mostra il passaggio chiave.',
                    'payoff_reveal' => 'Chiudi sul beneficio percepito.',
                    'cta_ending' => 'Scrivici per capire se fa per te.',
                ],
            ],
            'reel_blueprint' => [
                'continuity_lock' => 'Stesso volto e stessa ambientazione.',
                'shots' => [
                    [
                        'order' => 1,
                        'purpose' => 'hook immediato',
                        'subject' => 'persona del brand',
                        'camera' => 'medium shot verticale',
                        'motion' => 'push-in leggero',
                    ],
                    [
                        'order' => 2,
                        'purpose' => 'sviluppo chiaro',
                        'subject' => 'interazione col servizio',
                        'camera' => 'angolazione coerente',
                        'motion' => 'tracking morbido',
                    ],
                ],
            ],
            'ai_cta' => 'Scrivici per capire se fa per te.',
        ]);

        $this->assertSame(3, data_get($pack, 'scene_count'));
        $this->assertTrue((bool) data_get($pack, 'hook_scene_present'));
        $this->assertTrue((bool) data_get($pack, 'cta_scene_present'));
        $this->assertSame('hook', data_get($pack, 'scene_list.0.scene_type'));
        $this->assertSame('cta', data_get($pack, 'scene_list.2.scene_type'));
        $this->assertSame('final_cta', data_get($pack, 'scene_list.2.cta_role'));
        $this->assertSame('final_cta', data_get($pack, 'scene_list.2.CTA_role'));
        $this->assertSame('upper_third', data_get($pack, 'scene_list.0.text_overlay.safe_area'));
        $this->assertSame('lower_third', data_get($pack, 'scene_list.2.text_overlay.safe_area'));
    }

    public function test_it_builds_an_identity_first_segmented_reel_storyboard_with_safe_areas(): void
    {
        $pack = app(VideoScenePlanner::class)->build([
            'platform' => 'instagram',
            'format' => 'reel',
            'requested_total_seconds' => 20,
            'video_voiceover' => 'Parti dal punto che ferma il feed. Poi accompagna la scena con un gesto leggibile. Chiudi sul payoff e sulla CTA.',
            'hook_meta' => [
                'main_hook' => 'Qui si gioca tutto nei primi secondi.',
                'authority_cue' => 'Metodo reale, non improvvisazione.',
                'proof_or_trust_cue' => 'Volto e prodotto reali.',
            ],
            'content_structure_meta' => [
                'video_segments' => [
                    'development_3_8' => 'Sviluppa in modo social-native il passaggio che conta.',
                    'payoff_reveal' => 'Rendi chiaro il risultato percepito.',
                    'cta_ending' => 'Chiedi un confronto rapido.',
                ],
            ],
            'asset_identity' => [
                'locked_elements' => ['presenter', 'product'],
            ],
            'asset_variables' => [
                'resolved' => [
                    ['kind' => 'person', 'name' => 'Giorgia'],
                    ['kind' => 'product', 'name' => 'Prodotto Hero'],
                ],
            ],
            'reel_blueprint' => [
                'continuity_lock' => 'Stessa persona e stesso prodotto in tutte le scene.',
                'shots' => [
                    [
                        'order' => 1,
                        'purpose' => 'hook identitario',
                        'subject' => 'Giorgia con prodotto hero',
                        'camera' => 'medium shot',
                        'motion' => 'push-in',
                    ],
                    [
                        'order' => 2,
                        'purpose' => 'sviluppo tecnico',
                        'subject' => 'uso del prodotto',
                        'camera' => 'wide',
                        'motion' => 'tracking',
                    ],
                    [
                        'order' => 3,
                        'purpose' => 'secondo sviluppo',
                        'subject' => 'dettaglio servizio',
                        'camera' => 'close medium',
                        'motion' => 'micro parallax',
                    ],
                    [
                        'order' => 4,
                        'purpose' => 'payoff finale',
                        'subject' => 'Giorgia e prodotto ancora visibili',
                        'camera' => 'close',
                        'motion' => 'movimento conclusivo',
                    ],
                ],
            ],
            'ai_cta' => 'Chiedi un confronto rapido.',
        ]);

        $this->assertTrue((bool) data_get($pack, 'identity_first'));
        $this->assertSame(5, data_get($pack, 'scene_count'));
        $this->assertSame('hook', data_get($pack, 'scene_list.0.scene_type'));
        $this->assertSame('cta', data_get($pack, 'scene_list.4.scene_type'));
        $this->assertSame('final_cta', data_get($pack, 'scene_list.4.cta_role'));
        $this->assertContains('center_face_zone', (array) data_get($pack, 'scene_list.0.text_overlay.avoid_regions'));
        $this->assertSame('upper_left', data_get($pack, 'scene_list.0.text_overlay.position'));
        $this->assertSame('lower_center', data_get($pack, 'scene_list.4.text_overlay.position'));
        $this->assertGreaterThan(
            (int) data_get($pack, 'scene_list.0.timing_window.end_ms'),
            (int) data_get($pack, 'scene_list.4.timing_window.start_ms')
        );
    }
}
