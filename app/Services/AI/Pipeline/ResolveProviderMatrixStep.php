<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Services\AI\AiProviderMatrixService;
use App\Services\GenerationAuditService;

class ResolveProviderMatrixStep
{
    public function __construct(
        private readonly AiProviderMatrixService $aiProviderMatrixService,
        private readonly GenerationAuditService $generationAudit
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): GenerationPipelineState
    {
        $providerMatrix = $this->aiProviderMatrixService->resolve($state->meta, (int) $state->item->tenant_id);
        $state->meta['provider_matrix'] = $providerMatrix;
        $state->put('provider_matrix', $providerMatrix);

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
