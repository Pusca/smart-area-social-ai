<?php

namespace Tests\Unit;

use App\Jobs\GenerateAiForContentItem;
use App\Services\AI\GenerationVersionRegistry;
use Tests\TestCase;

class GenerationVersionRegistryTest extends TestCase
{
    public function test_it_builds_a_structured_version_map_for_the_generation_runtime(): void
    {
        $registry = app(GenerationVersionRegistry::class);

        $versionMap = $registry->versionMap(
            meta: [
                'video_provider' => 'runway',
                'video_generation' => ['provider' => 'runway'],
                'image_provider' => 'nanobanana',
                'text_provider_last_used' => 'openai',
                'speech_provider' => 'openai',
                'voice_clone_provider' => 'elevenlabs',
            ],
            providerMatrix: [
                'text' => ['provider' => 'openai'],
                'grader' => ['provider' => 'openai'],
                'image' => ['provider' => 'nanobanana'],
                'video' => ['provider' => 'runway'],
                'speech' => ['provider' => 'openai'],
                'voice_clone' => ['provider' => 'elevenlabs'],
            ],
            jobClass: GenerateAiForContentItem::class
        );

        $this->assertSame('generation_pipeline_v1', $versionMap['pipeline_version']);
        $this->assertSame('legacy_inline_prompts_v1', $versionMap['prompt_template_version']);
        $this->assertSame('editorial_strategy_compose_v1', $versionMap['strategy_composer_version']);
        $this->assertSame('tenant_feedback_memory_v1', $versionMap['feedback_synthesis_version']);
        $this->assertSame(GenerateAiForContentItem::class, $versionMap['job_class']);
        $this->assertSame('runway', data_get($versionMap, 'provider_adapter_versions.video.provider'));
        $this->assertSame('runway_video_adapter_v1', data_get($versionMap, 'provider_adapter_versions.video.adapter_version'));
        $this->assertSame('nanobanana_image_adapter_v1', data_get($versionMap, 'provider_adapter_versions.image.adapter_version'));
        $this->assertSame('openai_text_adapter_v1', data_get($versionMap, 'provider_adapter_versions.text.adapter_version'));
    }

    public function test_it_marks_unknown_provider_adapters_as_unversioned(): void
    {
        $registry = app(GenerationVersionRegistry::class);

        $this->assertSame('unversioned_adapter', $registry->adapterVersion('video', 'unknown-provider'));
    }
}
