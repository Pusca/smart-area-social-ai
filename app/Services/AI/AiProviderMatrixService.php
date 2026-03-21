<?php

namespace App\Services\AI;

class AiProviderMatrixService
{
    public function __construct(
        private readonly TenantFineTuningService $tenantFineTuningService,
        private readonly ProviderCapabilityRegistry $capabilityRegistry
    ) {
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function resolve(array $meta = [], ?int $tenantId = null): array
    {
        $text = $this->capabilityRegistry->resolveProvider(
            'text',
            (string) data_get($meta, 'provider_matrix.text.provider', data_get($meta, 'text_provider', '')),
            $this->capabilityRegistry->defaultProvider('text')
        );
        $grader = $this->capabilityRegistry->resolveProvider(
            'grader',
            (string) data_get($meta, 'provider_matrix.grader.provider', data_get($meta, 'grader_provider', '')),
            $this->capabilityRegistry->defaultProvider('grader')
        );
        $image = $this->capabilityRegistry->resolveProvider(
            'image',
            (string) data_get($meta, 'provider_matrix.image.provider', data_get($meta, 'image_provider', '')),
            $this->capabilityRegistry->defaultProvider('image')
        );
        $speech = $this->capabilityRegistry->resolveProvider(
            'speech',
            (string) data_get($meta, 'provider_matrix.speech.provider', data_get($meta, 'speech_provider', '')),
            $this->capabilityRegistry->defaultProvider('speech')
        );
        $voiceClone = $this->capabilityRegistry->resolveProvider(
            'voice_clone',
            (string) data_get($meta, 'provider_matrix.voice_clone.provider', data_get($meta, 'voice_clone_provider', '')),
            $this->capabilityRegistry->defaultProvider('voice_clone')
        );
        $video = $this->capabilityRegistry->resolveProvider(
            'video',
            (string) data_get($meta, 'provider_matrix.video.provider', data_get($meta, 'video_provider', '')),
            $this->capabilityRegistry->defaultProvider('video')
        );

        $matrix = [
            'text' => $this->matrixEntry('text', $text, [
                'model' => (string) data_get($meta, 'provider_matrix.text.model', ''),
            ]),
            'grader' => $this->matrixEntry('grader', $grader, [
                'model' => (string) data_get($meta, 'provider_matrix.grader.model', ''),
            ]),
            'alignment' => $this->matrixEntry('grader', $grader, [
                'model' => (string) data_get($meta, 'provider_matrix.alignment.model', data_get($meta, 'provider_matrix.grader.model', '')),
            ]),
            'image' => $this->matrixEntry('image', $image, [
                'model' => (string) data_get($meta, 'provider_matrix.image.model', data_get($meta, 'image_model', '')),
            ]),
            'speech' => $this->matrixEntry('speech', $speech, [
                'model' => (string) data_get($meta, 'provider_matrix.speech.model', data_get($meta, 'speech_model', '')),
            ]),
            'voice_clone' => $this->matrixEntry('voice_clone', $voiceClone, [
                'model' => (string) data_get($meta, 'provider_matrix.voice_clone.model', data_get($meta, 'voice_clone_model', '')),
            ]),
            'video' => $this->matrixEntry('video', $video, [
                'model' => (string) data_get($meta, 'provider_matrix.video.model', data_get($meta, 'video_model', '')),
            ]),
            'resolved_at' => now()->toDateTimeString(),
        ];

        if ($tenantId && $tenantId > 0) {
            $matrix = $this->tenantFineTuningService->decorateProviderMatrix($tenantId, $matrix);
        }

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function matrixEntry(string $area, string $provider, array $context = []): array
    {
        return [
            'provider' => $provider,
            'available' => $this->capabilityRegistry->allowedProviders($area),
            'configured' => $this->capabilityRegistry->isConfigured($provider, $area),
            'capabilities' => $this->capabilityRegistry->summary($provider, $area, $context),
        ];
    }
}