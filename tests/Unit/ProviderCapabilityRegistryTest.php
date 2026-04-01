<?php

namespace Tests\Unit;

use App\Services\AI\ProviderCapabilityRegistry;
use Tests\TestCase;

class ProviderCapabilityRegistryTest extends TestCase
{
    public function test_it_resolves_valid_and_invalid_providers_from_the_registry(): void
    {
        config()->set('generation.video_provider_default', 'runway');
        config()->set('generation.voice_clone_provider_default', 'elevenlabs');

        $registry = app(ProviderCapabilityRegistry::class);

        $this->assertSame(['openai', 'runway', 'kling'], $registry->allowedProviders('video'));
        $this->assertSame('runway', $registry->defaultProvider('video'));
        $this->assertSame('runway', $registry->resolveProvider('video', 'invalid-provider', 'runway'));
        $this->assertSame(['elevenlabs'], $registry->allowedProviders('voice_clone'));
        $this->assertSame('elevenlabs', $registry->defaultProvider('voice_clone'));
    }

    public function test_it_normalizes_video_duration_per_provider_and_model(): void
    {
        $registry = app(ProviderCapabilityRegistry::class);

        $this->assertSame(12, $registry->normalizeVideoDuration('openai', 10, 'sora-2'));
        $this->assertSame(8, $registry->normalizeVideoDuration('runway', 10, 'veo3.1_fast'));
        $this->assertSame(10, $registry->normalizeVideoDuration('runway', 12, 'gen4.5'));
        $this->assertSame(12, $registry->normalizeVideoDuration('kling', 12, 'kling-v3-omni'));
        $this->assertSame(3, $registry->normalizeVideoDuration('kling', 1, 'kling-v3'));
        $this->assertSame(5, $registry->normalizeVideoDuration('kling', 7, 'kling-v2-1'));
        $this->assertSame(10, $registry->normalizeVideoDuration('kling', 9, 'kling-v2-1'));
    }

    public function test_it_uses_context_specific_kling_models_for_text_and_reference_video_paths(): void
    {
        $registry = app(ProviderCapabilityRegistry::class);

        $this->assertSame('kling-v3-omni', $registry->defaultModel('kling', 'video', ['mode' => 'text']));
        $this->assertSame('kling-v3', $registry->defaultModel('kling', 'video', ['mode' => 'image']));
        $this->assertSame('kling-v3', $registry->defaultModel('kling', 'video', ['mode' => 'multi_image']));
    }

    public function test_it_respects_provider_lock_when_building_fallbacks(): void
    {
        config()->set('runway.api_key', 'runway-key');
        config()->set('openai.api_key', 'openai-key');
        config()->set('kling.access_key', 'kling-access');
        config()->set('kling.secret_key', 'kling-secret');

        $registry = app(ProviderCapabilityRegistry::class);

        $this->assertSame([], $registry->fallbackProviders('runway', 'video', true));
        $this->assertSame(['openai', 'kling'], $registry->fallbackProviders('runway', 'video', false));
    }

    public function test_it_reports_capability_mismatch_for_unsupported_or_incompatible_requests(): void
    {
        config()->set('nanobanana.api_key', 'nb-key');
        config()->set('openai.api_key', 'openai-key');

        $registry = app(ProviderCapabilityRegistry::class);

        $unsupported = $registry->capabilityMismatch('nanobanana', 'video', [
            'image_to_video' => true,
        ]);
        $strictMismatch = $registry->capabilityMismatch('openai', 'video', [
            'strict_asset_mode' => true,
            'duration_seconds' => 10,
            'model' => 'sora-2',
        ]);

        $this->assertFalse($unsupported['ok']);
        $this->assertContains('unsupported_area', $unsupported['issues']);

        $this->assertFalse($strictMismatch['ok']);
        $this->assertContains('strict_asset_mode_not_supported', $strictMismatch['issues']);
        $this->assertContains('duration_not_supported', $strictMismatch['issues']);
    }
}
