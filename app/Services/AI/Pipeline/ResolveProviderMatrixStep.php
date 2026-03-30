<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Services\AI\IdentityGuard;
use App\Services\AI\AiProviderMatrixService;
use App\Services\Assets\AssetScoringEngine;
use App\Services\GenerationAuditService;

class ResolveProviderMatrixStep
{
    public function __construct(
        private readonly AiProviderMatrixService $aiProviderMatrixService,
        private readonly GenerationAuditService $generationAudit,
        private readonly AssetScoringEngine $assetScoringEngine,
        private readonly IdentityGuard $identityGuard
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): GenerationPipelineState
    {
        $providerMatrix = $this->aiProviderMatrixService->resolve($state->meta, (int) $state->item->tenant_id);
        $state->meta['provider_matrix'] = $providerMatrix;
        $state->put('provider_matrix', $providerMatrix);

        $assetScoring = $this->assetScoringEngine->score(
            assetVariables: (array) $state->get('asset_variables', []),
            assetIdentity: (array) $state->get('asset_identity', []),
            context: [
                'tenant_id' => (int) $state->item->tenant_id,
                'content_item_id' => (int) $state->item->id,
                'format' => (string) $state->item->format,
                'platform' => (string) $state->item->platform,
                'strict_asset_mode' => $state->strictAssetMode,
                'provider_matrix' => $providerMatrix,
                'image_provider' => (string) data_get($providerMatrix, 'image.provider', ''),
                'video_provider' => (string) data_get($providerMatrix, 'video.provider', ''),
            ]
        );
        $state->meta['asset_scoring'] = $assetScoring;
        $state->put('asset_scoring', $assetScoring);
        $identityPreflight = (bool) config('social_manager.features.identity_guard_v1', true)
            ? $this->identityGuard->preflight(
                (array) $state->get('asset_identity', []),
                $assetScoring,
                $providerMatrix,
                [
                    'strict_asset_mode' => $state->strictAssetMode,
                ]
            )
            : [];
        if ($identityPreflight !== []) {
            $state->meta['identity_guard'] = array_replace_recursive(
                (array) data_get($state->meta, 'identity_guard', []),
                ['preflight' => $identityPreflight]
            );
            $state->put('identity_preflight', $identityPreflight);
        }

        $state->run = $this->generationAudit->syncRun($state->run, [
            'requested_provider_matrix' => $job->requestedProviderMatrixSnapshot($state->meta),
            'resolved_provider_matrix' => $providerMatrix,
            'requested_output' => $job->requestedOutputSummary($state->item, $state->meta),
            'version_meta' => $job->generationVersionMeta($state->meta, $providerMatrix),
        ]);
        $job->updateGenerationAuditMeta($state->item, $state->run?->id, 'running');
        $state->meta = array_merge(
            is_array($state->item->ai_meta) ? $state->item->ai_meta : [],
            $state->meta
        );

        $state->syncMetaToItem();
        $state->item->save();

        return $state;
    }
}
