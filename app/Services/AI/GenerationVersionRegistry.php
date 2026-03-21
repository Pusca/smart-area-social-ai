<?php

namespace App\Services\AI;

class GenerationVersionRegistry
{
    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilityRegistry
    ) {
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, mixed>
     */
    public function versionMap(array $meta = [], array $providerMatrix = [], ?string $jobClass = null): array
    {
        $versions = (array) config('ai_versioning.versions', []);

        return [
            'pipeline_version' => (string) data_get($versions, 'pipeline', 'generation_pipeline_v1'),
            'prompt_template_version' => (string) data_get($versions, 'prompt_template', 'legacy_inline_prompts_v1'),
            'strategy_composer_version' => (string) data_get($versions, 'strategy_composer', 'editorial_strategy_compose_v1'),
            'alignment_policy_version' => (string) data_get($versions, 'alignment_policy', 'content_alignment_v1'),
            'asset_selection_policy_version' => (string) data_get($versions, 'asset_selection_policy', 'asset_reference_selection_v1'),
            'feedback_synthesis_version' => (string) data_get($versions, 'feedback_synthesis', 'tenant_feedback_memory_v1'),
            'provider_adapter_versions' => $this->providerAdapterVersions($meta, $providerMatrix),
            'registry_source' => 'config.ai_versioning',
            'job_class' => $jobClass,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, array<string, string>>
     */
    public function providerAdapterVersions(array $meta = [], array $providerMatrix = []): array
    {
        $areas = ['text', 'grader', 'image', 'video', 'speech', 'voice_clone'];
        $resolved = [];

        foreach ($areas as $area) {
            $provider = $this->resolveProviderForArea($area, $meta, $providerMatrix);
            $resolved[$area] = [
                'provider' => $provider,
                'adapter_version' => $this->adapterVersion($area, $provider),
            ];
        }

        return $resolved;
    }

    public function adapterVersion(string $area, string $provider): string
    {
        $provider = trim(strtolower($provider));
        $version = (string) config('ai_versioning.provider_adapters.' . $area . '.' . $provider, '');

        return $version !== ''
            ? $version
            : 'unversioned_adapter';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $providerMatrix
     */
    private function resolveProviderForArea(string $area, array $meta, array $providerMatrix): string
    {
        $effectiveMap = [
            'text' => (string) data_get($meta, 'text_provider_last_used', ''),
            'grader' => (string) data_get($meta, 'grader_provider_last_used', ''),
            'image' => (string) data_get($meta, 'image_generation.provider', data_get($meta, 'image_provider', '')),
            'video' => (string) data_get($meta, 'video_generation.provider', data_get($meta, 'video_provider_last_used', data_get($meta, 'video_provider', ''))),
            'speech' => (string) data_get($meta, 'speech_provider_last_used', data_get($meta, 'speech_provider', '')),
            'voice_clone' => (string) data_get($meta, 'voice_clone_provider_last_used', data_get($meta, 'voice_clone_provider', '')),
        ];

        $provider = trim((string) ($effectiveMap[$area] ?? ''));
        if ($provider !== '') {
            return $this->capabilityRegistry->resolveProvider($area, $provider, $this->capabilityRegistry->defaultProvider($area));
        }

        $matrixProvider = trim((string) data_get($providerMatrix, $area . '.provider', ''));
        if ($matrixProvider !== '') {
            return $this->capabilityRegistry->resolveProvider($area, $matrixProvider, $this->capabilityRegistry->defaultProvider($area));
        }

        $requestedMetaKey = $area . '_provider';
        $requestedProvider = trim((string) data_get($meta, $requestedMetaKey, ''));

        return $this->capabilityRegistry->resolveProvider($area, $requestedProvider, $this->capabilityRegistry->defaultProvider($area));
    }
}
