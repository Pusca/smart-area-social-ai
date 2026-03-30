<?php

namespace Tests\Unit;

use App\Jobs\GenerateAiForContentItem;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class GenerateAiForContentItemTest extends TestCase
{
    public function test_it_marks_generic_runway_failures_as_fallback_candidates(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'shouldFallbackFromRunwayToOpenAi');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, new RuntimeException('Runway video generation failed: video_generation_failed')));
        $this->assertTrue($method->invoke($job, new RuntimeException('Runway asset download error (502) URL=https://example.com/video.mp4')));
        $this->assertFalse($method->invoke($job, new RuntimeException('Missing RUNWAY_API_KEY')));
        $this->assertFalse($method->invoke($job, new RuntimeException('Runway video create error (400) BODY=validation error')));
    }

    public function test_it_marks_generic_runway_failures_as_intra_provider_retry_candidates(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'shouldRetryRunwayInsideProvider');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, new RuntimeException('Runway video generation failed: video_generation_failed (status=FAILED)')));
        $this->assertTrue($method->invoke($job, new RuntimeException('Runway video generation timeout after 420s (status=in_progress)')));
        $this->assertFalse($method->invoke($job, new RuntimeException('Runway video generation failed: safety_rejected: video_generation_failed')));
    }

    public function test_it_builds_runway_recovery_plans_with_gen45_retry_and_stability_prompt(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildRunwayRecoveryPlans');
        $method->setAccessible(true);

        $plans = $method->invoke($job, [
            'model' => 'veo3.1_fast',
            'seconds' => 8,
            'size' => '720x1280',
        ], 'Create a premium branded reel with the provided references.', 'Mostra la persona reale del brand nel locale.');

        $this->assertCount(3, $plans);
        $this->assertSame('veo3.1_fast', $plans[0]['model']);
        $this->assertSame('primary', $plans[0]['reason']);
        $this->assertSame('gen4.5', $plans[1]['model']);
        $this->assertSame('gen45_model_retry', $plans[1]['reason']);
        $this->assertSame('gen4.5', $plans[2]['model']);
        $this->assertSame('gen45_stability_retry', $plans[2]['reason']);
    }

    public function test_it_disables_cross_provider_video_fallback_when_provider_is_locked(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'shouldAllowCrossProviderVideoFallback');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($job, [
            'video_provider' => 'runway',
            'video_provider_lock' => true,
        ]));
        $this->assertTrue($method->invoke($job, [
            'video_provider' => 'runway',
        ]));
    }

    public function test_it_builds_creative_direction_prompt_instructions_for_overlay_and_continuity(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'creativeDirectionPromptInstruction');
        $method->setAccessible(true);

        $instruction = $method->invoke($job, [
            'creative_direction' => [
                'professional_direction' => [
                    'quality_bar' => 'Il contenuto deve sembrare costruito per un pubblico reale e specifico.',
                ],
                'trend_policy' => [
                    'disallowed_mechanics' => ['meme scollegati dal brand'],
                ],
            ],
        ], [
            'viral_hook_style' => 'Hook forte nel primo secondo.',
            'shareability_driver' => 'Takeaway salvabile.',
            'trend_bridge' => 'Usa il trend come struttura, non come meme.',
            'overlay_brief' => 'safe area upper third, max 5 parole.',
            'continuity_brief' => 'Mantieni volto e showroom reali.',
        ]);

        $this->assertStringContainsString('pubblico reale e specifico', $instruction);
        $this->assertStringContainsString('Hook social', $instruction);
        $this->assertStringContainsString('overlay-ready', $instruction);
        $this->assertStringContainsString('Mantieni volto e showroom reali', $instruction);
        $this->assertStringContainsString('meme scollegati dal brand', $instruction);
    }

    public function test_it_builds_a_safer_openai_fallback_prompt_for_multi_reference_videos(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildOpenAiVideoFallbackPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $job,
            'Genera un reel sociale realistico del ristorante.',
            'Fammi un reel dove si vedano sala, veranda e terrazza.',
            ['a.jpg', 'b.jpg', 'c.jpg']
        );

        $this->assertStringContainsString('Fallback di sicurezza', $prompt);
        $this->assertStringContainsString('mostrali in sequenza', $prompt);
        $this->assertStringContainsString('Fammi un reel dove si vedano sala, veranda e terrazza.', $prompt);
    }

    public function test_it_builds_a_video_prompt_that_keeps_real_locations_in_sequence(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildStrategicVideoPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $job,
            new \App\Models\ContentItem(['format' => 'reel']),
            [
                'tenant_profile' => [
                    'business_name' => 'Do Mori',
                    'industry' => 'Ristorante',
                ],
                'item_brain' => [
                    'objective' => 'Awareness',
                ],
                'strategy' => [
                    'brand_voice' => [
                        'tone' => 'amichevole',
                    ],
                ],
            ],
            'Mostra sala, veranda e terrazza del ristorante.',
            'brand-assets/7/images/sala.jpg',
            ['brand-assets/7/images/sala.jpg'],
            ['brand-assets/7/images/sala.jpg', 'brand-assets/7/images/veranda.jpg', 'brand-assets/7/images/terrazza.jpg'],
            [
                'resolved' => [
                    ['name' => 'Sala 1', 'kind' => 'location'],
                    ['name' => 'Veranda Fuori', 'kind' => 'location'],
                    ['name' => 'Terrazza Esterna', 'kind' => 'location'],
                ],
            ],
            [],
            false,
            null,
            'scene',
            true,
            false
        );

        $this->assertStringContainsString('verticale 9:16', $prompt);
        $this->assertStringContainsString('mostrali in sequenza', strtolower($prompt));
        $this->assertStringNotContainsString('4:5', $prompt);
        $this->assertStringContainsString('Sala 1', $prompt);
    }

    public function test_it_enhances_runway_reel_prompt_with_social_sequence_direction(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildRunwayReelExecutionPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $job,
            'Crea un reel verticale 9:16 del ristorante.',
            new \App\Models\ContentItem(['format' => 'reel']),
            [
                'item_brain' => [
                    'objective' => 'Awareness',
                    'angle' => 'atmosfera serale del locale',
                    'series' => 'signature reels',
                ],
                'reel_blueprint' => [
                    'hook' => 'Ingresso visivo forte sul locale',
                    'anchor_frame' => 'frame verticale iniziale sul ristorante reale',
                    'continuity_lock' => 'stessa persona e stesso locale in tutti gli shot',
                    'visual_payoff' => 'chiusura premium sul momento aperitivo',
                    'shots' => [
                        [
                            'order' => 1,
                            'purpose' => 'hook immediato',
                            'subject' => 'ingresso del ristorante',
                            'camera' => 'wide shot verticale',
                            'motion' => 'push-in leggero',
                        ],
                        [
                            'order' => 2,
                            'purpose' => 'sviluppo atmosfera',
                            'subject' => 'persona del brand in sala',
                            'camera' => 'medium shot',
                            'motion' => 'tracking morbido',
                        ],
                        [
                            'order' => 3,
                            'purpose' => 'payoff finale',
                            'subject' => 'dettaglio premium aperitivo',
                            'camera' => 'close medium',
                            'motion' => 'micro parallax',
                        ],
                    ],
                ],
                'strategy' => [
                    'brand_voice' => [
                        'tone' => 'caldo e premium',
                    ],
                ],
            ],
            [
                'resolved' => [
                    [
                        'name' => 'Erika',
                        'kind' => 'person',
                    ],
                ],
            ]
        );

        $this->assertStringContainsString('3-5 shot concatenati', $prompt);
        $this->assertStringContainsString('hook visivo entro il primo secondo', strtolower($prompt));
        $this->assertStringContainsString('stop-scroll', strtolower($prompt));
        $this->assertStringContainsString('Anchor frame:', $prompt);
        $this->assertStringContainsString('Shot 1:', $prompt);
        $this->assertStringContainsString('Awareness', $prompt);
        $this->assertStringContainsString('atmosfera serale del locale', $prompt);
        $this->assertStringContainsString('signature reels', $prompt);
        $this->assertStringContainsString('stessa in tutti gli shot', strtolower($prompt));
    }

    public function test_it_prefers_gen45_over_veo_for_reference_heavy_runway_jobs(): void
    {
        config()->set('runway.model', 'veo3.1_fast');

        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'resolveRunwayVideoModel');
        $method->setAccessible(true);

        $model = $method->invoke(
            $job,
            new \App\Models\ContentItem(['format' => 'reel']),
            [],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                    ],
                ],
            ],
            ['brand-assets/11/persona/front.jpg']
        );

        $this->assertSame('gen4.5', $model);
    }

    public function test_it_builds_a_fallback_reel_blueprint_when_none_is_present(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'normalizeReelBlueprint');
        $method->setAccessible(true);

        $blueprint = $method->invoke(
            $job,
            [],
            new \App\Models\ContentItem(['format' => 'reel']),
            [
                'item_brain' => [
                    'objective' => 'Lead generation',
                    'angle' => 'persona reale al lavoro',
                ],
                'strategy' => [
                    'brand_voice' => [
                        'tone' => 'professionale e umano',
                    ],
                ],
            ],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                    ],
                ],
            ],
            'Crea un reel verticale per la titolare del brand.'
        );

        $this->assertIsArray($blueprint);
        $this->assertCount(3, $blueprint['shots']);
        $this->assertStringContainsString('apertura forte', strtolower($blueprint['hook']));
        $this->assertStringContainsString('stessa persona', strtolower($blueprint['continuity_lock']));
    }

    public function test_it_builds_a_kling_prompt_that_locks_identity_across_shots(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildKlingExecutionPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $job,
            'Crea un reel verticale 9:16 per il brand.',
            new \App\Models\ContentItem(['format' => 'reel']),
            [
                'item_brain' => [
                    'objective' => 'Lead generation',
                    'angle' => 'viso reale della titolare',
                    'series' => 'persona reels',
                ],
                'strategy' => [
                    'brand_voice' => [
                        'tone' => 'premium e umano',
                    ],
                ],
            ],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                        'profile' => [
                            'shot_summary' => [
                                ['slot' => 'front'],
                                ['slot' => 'three_quarter_left'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'category' => 'realism',
                'scope' => 'visual_first',
                'reason' => 'Non sembra lei',
            ]
        );

        $this->assertStringContainsString('same real subject from different angles', strtolower($prompt));
        $this->assertStringContainsString('same face', strtolower($prompt));
        $this->assertStringContainsString('native instagram reel', strtolower($prompt));
        $this->assertStringContainsString('Lead generation', $prompt);
        $this->assertStringContainsString('persona reels', $prompt);
        $this->assertStringContainsString('visibly improve', strtolower($prompt));
    }

    public function test_it_builds_a_kling_negative_prompt_for_identity_and_location_protection(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildKlingNegativePrompt');
        $method->setAccessible(true);

        $negative = $method->invoke(
            $job,
            new \App\Models\ContentItem(['format' => 'reel']),
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                    ],
                ],
            ],
            [
                'category' => 'visual_composition',
                'scope' => 'visual_first',
                'reason' => 'Non sembra lei',
            ],
            true
        );

        $this->assertStringContainsString('identity drift', strtolower($negative));
        $this->assertStringContainsString('different face', strtolower($negative));
        $this->assertStringContainsString('merged rooms', strtolower($negative));
        $this->assertStringContainsString('too similar to previous version', strtolower($negative));
    }

    public function test_it_detects_openai_video_moderation_blocks(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'isOpenAiVideoModerationBlock');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, new RuntimeException('Video generation failed: Your request was blocked by our moderation system.')));
        $this->assertTrue($method->invoke($job, new RuntimeException('OpenAI video create error (400) BODY={"error":{"message":"content policy violation"}}')));
        $this->assertFalse($method->invoke($job, new RuntimeException('Video generation timeout after 420s')));
    }

    public function test_it_marks_openai_video_timeouts_as_secondary_fallback_candidates(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'shouldFallbackFromOpenAiToSecondaryProvider');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, new RuntimeException('Video generation timeout after 420s (status=in_progress)')));
        $this->assertFalse($method->invoke($job, new RuntimeException('OpenAI video create error (400) BODY={"error":{"message":"input_reference invalid"}}')));
    }

    public function test_it_builds_extended_video_single_clip_fallback_metadata(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildExtendedVideoSingleClipFallback');
        $method->setAccessible(true);

        $fallback = $method->invoke(
            $job,
            'openai',
            20,
            ['model' => 'sora-2', 'size' => '720x1280'],
            'ffmpeg.exe'
        );

        $this->assertSame('single_clip_fallback', $fallback['mode']);
        $this->assertSame('ffmpeg_unavailable', $fallback['reason']);
        $this->assertSame('openai', $fallback['provider']);
        $this->assertSame('sora-2', $fallback['model']);
        $this->assertSame(20, $fallback['requested_total_seconds']);
        $this->assertSame(12, $fallback['delivered_seconds']);
        $this->assertSame('720x1280', $fallback['size']);
        $this->assertSame('ffmpeg.exe', $fallback['ffmpeg_binary']);
        $this->assertNotEmpty($fallback['at']);
    }

    public function test_it_normalizes_openai_video_seconds_to_supported_values(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'normalizeVideoOptionsForProvider');
        $method->setAccessible(true);

        $normalized = $method->invoke($job, 'openai', [
            'model' => 'sora-2',
            'seconds' => 10,
            'size' => '720x1280',
        ]);

        $this->assertSame(12, $normalized['seconds']);
    }

    public function test_it_defaults_reels_to_twenty_seconds_when_no_explicit_duration_is_present(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'targetVideoSecondsForFormat');
        $method->setAccessible(true);

        $seconds = $method->invoke($job, new \App\Models\ContentItem(['format' => 'reel']));

        $this->assertSame('20', $seconds);
    }

    public function test_it_normalizes_runway_veo_video_seconds_to_supported_values(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'normalizeVideoOptionsForProvider');
        $method->setAccessible(true);

        $normalized = $method->invoke($job, 'runway', [
            'model' => 'veo3.1_fast',
            'seconds' => 10,
            'size' => '720x1280',
        ]);

        $this->assertSame(8, $normalized['seconds']);
    }

    public function test_it_marks_video_result_as_single_clip_fallback_when_extended_generation_is_downgraded(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'applyExtendedVideoSingleClipFallback');
        $method->setAccessible(true);

        $result = $method->invoke(
            $job,
            [
                'source' => 'sora_video_generation',
                'provider' => 'openai',
                'video_path' => 'ai/videos/2026/03/test.mp4',
                'request_summary' => [
                    'seconds' => '12',
                    'size' => '720x1280',
                ],
            ],
            [
                'reason' => 'ffmpeg_unavailable',
                'requested_total_seconds' => 20,
                'delivered_seconds' => 12,
                'provider' => 'openai',
            ]
        );

        $this->assertSame('single_clip_fallback', $result['request_summary']['mode']);
        $this->assertTrue($result['request_summary']['extended_requested']);
        $this->assertSame('ffmpeg_unavailable', $result['request_summary']['fallback_reason']);
        $this->assertSame(20, $result['request_summary']['target_total_seconds']);
        $this->assertSame(12, $result['request_summary']['delivered_seconds']);
        $this->assertFalse($result['extended']);
        $this->assertSame(1, $result['segment_count']);
        $this->assertSame(20, $result['target_total_seconds']);
        $this->assertSame([], $result['segments']);
        $this->assertSame('ffmpeg_unavailable', $result['extended_fallback']['reason']);
    }

    public function test_it_realigns_single_clip_fallback_duration_when_provider_falls_back_to_openai(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'applyExtendedVideoSingleClipFallback');
        $method->setAccessible(true);

        $result = $method->invoke(
            $job,
            [
                'source' => 'sora_video_generation',
                'provider' => 'openai',
                'video_path' => 'ai/videos/2026/03/test.mp4',
            ],
            [
                'reason' => 'ffmpeg_unavailable',
                'requested_total_seconds' => 20,
                'delivered_seconds' => 10,
                'provider' => 'runway',
                'model' => 'veo3.1_fast',
            ]
        );

        $this->assertSame(12, $result['request_summary']['delivered_seconds']);
        $this->assertSame('openai', $result['extended_fallback']['provider']);
        $this->assertSame('sora_video_generation', $result['source']);
        $this->assertSame(12, $result['extended_fallback']['delivered_seconds']);
    }

    public function test_it_builds_a_moderation_safe_video_prompt_for_guided_person_wellness_content(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'buildOpenAiVideoModerationRetryPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $job,
            'Sequenza video di Giorgia che esegue un massaggio tecnico sulle spalle e schiena del cliente.',
            'Un video di Giorgia per far capire quanto e brava a fare i massaggi tecnici.',
            ['brand-assets/11/persona/front.jpg'],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                        'profile' => [
                            'role' => 'Titolare',
                        ],
                    ],
                ],
            ]
        );

        $this->assertStringNotContainsString('Giorgia', $prompt);
        $this->assertStringNotContainsString('massaggio tecnico', strtolower($prompt));
        $this->assertStringContainsString('trattamento professionale', strtolower($prompt));
        $this->assertStringContainsString('persona di riferimento del brand', strtolower($prompt));
        $this->assertStringContainsString('senza sensualizzare la scena', strtolower($prompt));
    }

    public function test_it_prepares_openai_video_prompt_safely_before_execution_for_wellness_persona_content(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'prepareOpenAiVideoPromptForExecution');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $job,
            'Sequenza video di Giorgia che esegue un massaggio tecnico sulle spalle e schiena del cliente.',
            'Un video di Giorgia per far capire quanto e brava a fare i massaggi tecnici.',
            ['brand-assets/11/persona/front.jpg'],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                        'profile' => [
                            'role' => 'Titolare',
                        ],
                    ],
                ],
            ]
        );

        $this->assertStringNotContainsString('Giorgia', $prompt);
        $this->assertStringNotContainsString('massaggio tecnico', strtolower($prompt));
        $this->assertStringContainsString('trattamento professionale', strtolower($prompt));
    }

    public function test_it_filters_non_image_reference_paths_from_visual_reference_pool(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand-assets/11/persona/front.jpg', 'img');
        Storage::disk('public')->put('brand-assets/11/persona/reference.mp4', 'vid');

        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'filterReferenceImagePaths');
        $method->setAccessible(true);

        $paths = $method->invoke($job, [
            'brand-assets/11/persona/front.jpg',
            'brand-assets/11/persona/reference.mp4',
        ]);

        $this->assertSame(['brand-assets/11/persona/front.jpg'], $paths);
    }

    public function test_feedback_driven_video_instruction_forces_material_change_and_identity_lock(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'feedbackDrivenVideoInstruction');
        $method->setAccessible(true);

        $instruction = $method->invoke(
            $job,
            [
                'category' => 'visual_composition',
                'scope' => 'visual_first',
                'reason' => 'Non sembra lei e il video e troppo simile a prima',
            ],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                        'profile' => [
                            'immutable_traits' => 'volto, capelli, lineamenti',
                        ],
                    ],
                ],
            ],
            false
        );

        $this->assertStringContainsString('deve cambiare in modo evidente', strtolower($instruction));
        $this->assertStringContainsString('stessa tra una versione e l altra', strtolower($instruction));
        $this->assertStringContainsString('sembrare davvero quella dei riferimenti', strtolower($instruction));
        $this->assertStringContainsString('cambia in modo netto lo shot plan', strtolower($instruction));
    }

    public function test_it_prioritizes_person_reference_pool_using_identity_pack_canonicals(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'prioritizeVideoReferencePoolsForPersonVariable');
        $method->setAccessible(true);

        [$abs, $paths] = $method->invoke(
            $job,
            ['abs-profile', 'abs-front', 'abs-half', 'abs-left'],
            ['profile.jpg', 'front.jpg', 'half.jpg', 'left.jpg'],
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'left.jpg'],
                            ],
                        ],
                        'profile' => [
                            'shot_summary' => [
                                ['slot' => 'front', 'path' => 'front.jpg'],
                                ['slot' => 'three_quarter_left', 'path' => 'left.jpg'],
                                ['slot' => 'half_body', 'path' => 'half.jpg'],
                                ['slot' => 'profile', 'path' => 'profile.jpg'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame(['left.jpg', 'front.jpg', 'half.jpg', 'profile.jpg'], $paths);
        $this->assertSame(['abs-left', 'abs-front', 'abs-half', 'abs-profile'], $abs);
    }

    public function test_it_uses_primary_person_reference_mode_for_openai_identity_videos(): void
    {
        $job = new GenerateAiForContentItem(1);

        $this->assertTrue($job->shouldUseOpenAiPrimaryPersonReference(
            'openai',
            false,
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                    ],
                ],
            ],
            ['front.jpg', 'left.jpg']
        ));

        $this->assertFalse($job->shouldUseOpenAiPrimaryPersonReference(
            'openai',
            true,
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                    ],
                ],
            ],
            ['front.jpg', 'left.jpg']
        ));
    }

    public function test_it_skips_locked_scene_reference_for_kling_dual_subject_videos(): void
    {
        $job = new GenerateAiForContentItem(1);

        $this->assertFalse($job->shouldAttemptLockedVideoSceneReference('kling', true));
        $this->assertFalse($job->shouldUseLockedVideoSceneReference([
            'abs' => '/tmp/scene.png',
            'all_present' => true,
        ], 'kling', true));
        $this->assertFalse($job->shouldUseLockedVideoSceneReference([
            'abs' => '/tmp/scene.png',
            'all_present' => false,
        ], 'runway', true));
        $this->assertTrue($job->shouldUseLockedVideoSceneReference([
            'abs' => '/tmp/scene.png',
            'all_present' => true,
        ], 'runway', true));
    }

    public function test_it_preserves_presenter_and_product_canonicals_in_video_reference_selection_for_non_openai_providers(): void
    {
        $job = new GenerateAiForContentItem(1);

        $selected = $job->applyIdentityPackReferenceSelection(
            ['presenter-front.jpg', 'product-front.jpg', 'generic.jpg'],
            [
                'resolved' => [
                    [
                        'name' => 'Brand Presenter',
                        'kind' => 'person',
                        'asset_role' => 'presenter',
                        'canonical_asset_path' => 'presenter-front.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'presenter-front.jpg', 'is_primary' => true],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Hero Product',
                        'kind' => 'product',
                        'asset_role' => 'hero_product',
                        'canonical_asset_path' => 'product-front.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'product-front.jpg', 'is_primary' => true],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slots' => [
                    'presenter' => [
                        'canonical_asset_path' => 'presenter-front.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'presenter-front.jpg', 'is_primary' => true],
                            ],
                        ],
                    ],
                    'product' => [
                        'canonical_asset_path' => 'product-front.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'product-front.jpg', 'is_primary' => true],
                            ],
                        ],
                    ],
                ],
            ],
            true,
            [
                'selection_area' => 'video',
                'provider' => 'kling',
                'reference_paths' => ['presenter-front.jpg'],
                'fallback_paths' => ['product-front.jpg'],
            ]
        );

        $this->assertSame(['presenter-front.jpg', 'product-front.jpg'], $selected);
    }

    public function test_it_enforces_video_reference_validation_for_identity_locked_assets_even_without_manual_refs(): void
    {
        $job = new GenerateAiForContentItem(1);

        $this->assertTrue($job->shouldValidateVideoReferenceMatch(
            false,
            false,
            [
                'resolved' => [
                    [
                        'name' => 'Giorgia',
                        'kind' => 'person',
                    ],
                ],
            ],
            [
                'asset_identity' => [
                    'slots' => [
                        'presenter' => [
                            'id' => 12,
                        ],
                    ],
                ],
            ],
            ['abs-front', 'abs-left']
        ));

        $this->assertFalse($job->shouldValidateVideoReferenceMatch(
            false,
            true,
            [
                'resolved' => [
                    [
                        'name' => 'Sala',
                        'kind' => 'location',
                    ],
                ],
            ],
            [
                'asset_identity' => [
                    'slots' => [
                        'place' => [
                            'id' => 99,
                        ],
                    ],
                ],
            ],
            ['abs-room']
        ));
    }

    public function test_it_normalizes_structured_feedback_requests_and_keeps_audio_only_feedback_out_of_visual_flow(): void
    {
        $job = new GenerateAiForContentItem(1);

        $normalized = $job->normalizeFeedbackRequest([
            'sentiment' => 'dislike',
            'category' => 'tone_of_voice',
            'scope' => 'full',
            'reason' => 'La voce audio sembra robotica e poco naturale.',
            'action' => 'regenerate',
        ]);

        $this->assertSame('audio_unatural', $normalized['normalized_category']);
        $this->assertSame('medium', $normalized['severity']);
        $this->assertFalse($job->feedbackTargetsVisual($normalized));
    }

    public function test_it_builds_asset_identity_prompt_hint_for_location_with_maintain_and_change_sections(): void
    {
        $job = new GenerateAiForContentItem(1);

        $hint = $job->buildAssetIdentityPromptHint([
            'slots' => [
                'place' => [
                    'name' => 'Showroom Milano',
                    'identity_pack' => [
                        'descriptor' => ['summary' => 'Parete logo, vetrate e bancone frontale.'],
                    ],
                    'maintain_elements' => ['parete logo', 'vetrate', 'bancone'],
                    'changeable_elements' => ['decorazioni natalizie', 'props stagionali'],
                ],
            ],
            'maintain_elements' => ['parete logo', 'vetrate', 'bancone'],
            'changeable_elements' => ['decorazioni natalizie', 'props stagionali'],
            'consistency_mode' => 'strict',
        ]);

        $this->assertStringContainsString('mantieni: parete logo, vetrate', $hint);
        $this->assertStringContainsString('puoi variare: decorazioni natalizie, props stagionali', $hint);
        $this->assertStringContainsString('consistency: strict', $hint);
    }

    public function test_it_builds_asset_variable_prompt_hint_for_product_identity_pack(): void
    {
        $job = new GenerateAiForContentItem(1);

        $hint = $job->buildAssetVariablePromptHint([
            'resolved' => [
                [
                    'name' => 'Linea Premium',
                    'kind' => 'product',
                    'asset_role' => 'hero_product',
                    'consistency_threshold' => 91,
                    'identity_pack' => [
                        'strictness_level' => 'strict',
                        'canonical_assets' => [
                            ['path' => 'product/front.jpg'],
                        ],
                        'invariants' => ['forma flacone', 'etichetta', 'colori packaging'],
                        'transformables' => ['props stagionali', 'ambientazione premium'],
                        'visual_tags' => ['packaging nero opaco', 'dettagli oro'],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('Linea Premium [product]', $hint);
        $this->assertStringContainsString('mantieni: forma flacone, etichetta, colori packaging', $hint);
        $this->assertStringContainsString('puoi variare: props stagionali, ambientazione premium', $hint);
        $this->assertStringContainsString('strictness: strict', $hint);
    }

    public function test_it_applies_identity_pack_reference_selection_in_strict_mode(): void
    {
        $job = new GenerateAiForContentItem(1);

        $selected = $job->applyIdentityPackReferenceSelection(
            ['support.jpg', 'canonical.jpg', 'other.jpg'],
            [
                'resolved' => [
                    [
                        'kind' => 'product',
                        'canonical_asset_path' => 'canonical.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'canonical.jpg'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slots' => [
                    'product' => [
                        'canonical_assets' => [
                            ['path' => 'canonical.jpg'],
                        ],
                    ],
                ],
            ],
            true
        );

        $this->assertSame(['canonical.jpg'], $selected);
    }

    public function test_it_prefers_primary_canonical_references_in_strict_mode(): void
    {
        $job = new GenerateAiForContentItem(1);

        $selected = $job->applyIdentityPackReferenceSelection(
            ['support.jpg', 'primary.jpg', 'secondary.jpg', 'other.jpg'],
            [
                'resolved' => [
                    [
                        'name' => 'Brand Presenter',
                        'kind' => 'person',
                        'canonical_asset_path' => 'primary.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'secondary.jpg', 'is_primary' => false],
                                ['path' => 'primary.jpg', 'is_primary' => true],
                            ],
                        ],
                    ],
                ],
            ],
            [],
            true
        );

        $this->assertSame(['primary.jpg'], $selected);
    }

    public function test_it_uses_storyboard_speech_plan_for_video_narration(): void
    {
        $job = new GenerateAiForContentItem(1);
        $item = new \App\Models\ContentItem([
            'format' => 'reel',
            'ai_meta' => [
                'storyboard_meta' => [
                    'speech_plan' => [
                        'full_text' => 'Apri sul contrasto giusto. Poi accompagna il passaggio chiave. Chiudi con una CTA morbida.',
                    ],
                ],
                'video_voiceover' => 'Questo testo non dovrebbe essere usato.',
            ],
        ]);

        $narration = $job->resolveNarrationTextForVideo($item);

        $this->assertStringContainsString('Apri sul contrasto giusto', $narration);
        $this->assertStringNotContainsString('non dovrebbe essere usato', $narration);
    }

    public function test_it_builds_extended_segment_prompts_from_storyboard_scenes(): void
    {
        $job = new GenerateAiForContentItem(1);

        $prompts = $job->buildExtendedVideoSegmentPrompts(
            provider: 'runway',
            item: new \App\Models\ContentItem(['format' => 'reel']),
            meta: [
                'reel_blueprint' => [
                    'hook' => 'Hook forte',
                    'continuity_lock' => 'Stessa persona e stesso prodotto',
                    'visual_payoff' => 'Payoff finale',
                    'shots' => [
                        ['purpose' => 'hook', 'subject' => 'persona', 'camera' => 'wide', 'motion' => 'push-in'],
                        ['purpose' => 'sviluppo', 'subject' => 'prodotto', 'camera' => 'medium', 'motion' => 'tracking'],
                        ['purpose' => 'payoff', 'subject' => 'persona e prodotto', 'camera' => 'close', 'motion' => 'micro parallax'],
                    ],
                ],
                'storyboard_meta' => [
                    'scene_list' => [
                        [
                            'scene_index' => 1,
                            'scene_type' => 'hook',
                            'shot_objective' => 'hook iniziale',
                            'text_overlay' => ['safe_area' => 'upper_third'],
                        ],
                        [
                            'scene_index' => 2,
                            'scene_type' => 'development',
                            'shot_objective' => 'sviluppo tecnico',
                            'text_overlay' => ['safe_area' => 'upper_third'],
                        ],
                        [
                            'scene_index' => 3,
                            'scene_type' => 'payoff',
                            'shot_objective' => 'payoff finale',
                            'text_overlay' => ['safe_area' => 'lower_third'],
                        ],
                        [
                            'scene_index' => 4,
                            'scene_type' => 'cta',
                            'shot_objective' => 'CTA finale',
                            'text_overlay' => ['safe_area' => 'lower_third'],
                        ],
                    ],
                ],
            ],
            basePrompt: 'Crea un reel premium verticale 9:16.',
            segmentCount: 2
        );

        $this->assertCount(2, $prompts);
        $this->assertStringContainsString('Scene plan for this segment', $prompts[0]);
        $this->assertStringContainsString('hook iniziale', $prompts[0]);
        $this->assertStringContainsString('CTA finale', $prompts[1]);
    }
}


