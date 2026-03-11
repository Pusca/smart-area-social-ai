<?php

namespace Tests\Unit;

use App\Jobs\GenerateAiForContentItem;
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

    public function test_it_detects_openai_video_moderation_blocks(): void
    {
        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'isOpenAiVideoModerationBlock');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, new RuntimeException('Video generation failed: Your request was blocked by our moderation system.')));
        $this->assertTrue($method->invoke($job, new RuntimeException('OpenAI video create error (400) BODY={"error":{"message":"content policy violation"}}')));
        $this->assertFalse($method->invoke($job, new RuntimeException('Video generation timeout after 420s')));
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

    public function test_it_marks_openai_moderation_failures_as_runway_fallback_candidates_when_runway_is_configured(): void
    {
        config()->set('runway.api_key', 'runway-test-key');

        $job = new GenerateAiForContentItem(1);
        $method = new ReflectionMethod($job, 'shouldFallbackFromOpenAiToRunway');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, new RuntimeException('Video generation failed: Your request was blocked by our moderation system.')));
        $this->assertFalse($method->invoke($job, new RuntimeException('Video generation timeout after 420s')));
    }
}
