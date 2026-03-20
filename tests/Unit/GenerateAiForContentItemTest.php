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

    public function test_it_prioritizes_person_reference_pool_using_shot_order(): void
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

        $this->assertSame(['front.jpg', 'left.jpg', 'half.jpg', 'profile.jpg'], $paths);
        $this->assertSame(['abs-front', 'abs-left', 'abs-half', 'abs-profile'], $abs);
    }

}
