<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Services\AI\IdentityGuard;
use App\Services\AI\PublishReadinessGate;
use App\Services\AI\GenerationQualityScorecardService;
use App\Services\GenerationAuditService;
use App\Services\GenerationMetricsService;
use App\Services\Notification\WorkspaceNotificationService;

class PersistGenerationOutputsStep
{
    public function __construct(
        private readonly GenerationAuditService $generationAudit,
        private readonly GenerationMetricsService $generationMetrics,
        private readonly GenerationQualityScorecardService $qualityScorecard,
        private readonly IdentityGuard $identityGuard,
        private readonly PublishReadinessGate $publishReadinessGate,
        private readonly WorkspaceNotificationService $workspaceNotifications
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): void
    {
        $item = $state->item;

        if ($state->strictAssetMode && !$job->hasGeneratedVisualOutput($item)) {
            $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
            $errorMetaKey = $job->isVideoFormat($item) ? 'video_error' : 'image_error';
            $baseError = trim((string) ($item->ai_error ?? ''));
            if ($baseError === '') {
                $baseError = trim((string) data_get($metaNow, $errorMetaKey, ''));
            }

            $item->ai_status = 'error';
            $item->ai_error = $baseError !== ''
                ? $baseError . ' | STRICT_MODE_NO_VISUAL_OUTPUT'
                : 'STRICT_MODE_NO_VISUAL_OUTPUT';
            $item->ai_generated_at = now();
            $runMetrics = $this->generationMetrics->buildRunMetrics($item, $state->run);
            $state->run = $this->generationAudit->completeRun($state->run, [
                'status' => 'failed',
                'effective_output' => $job->buildRunEffectiveOutput($item),
                'result_summary' => $job->buildRunResultSummary($item),
                'overlay_meta' => (array) data_get($item->ai_meta, 'overlay_meta', []),
                'storyboard_meta' => (array) data_get($item->ai_meta, 'storyboard_meta', []),
                'last_error' => (string) $item->ai_error,
                'version_meta' => $job->generationVersionMeta(
                    is_array($item->ai_meta) ? $item->ai_meta : []
                ),
            ] + $runMetrics);
            $scorecard = $this->qualityScorecard->buildForContentItem($item, $state->run);
            $identityValidation = $this->identityGuard->validateFinal($item, $state->run);
            $publishGate = $this->publishReadinessGate->decideForContentItem($item, $state->run, $scorecard);
            $state->run = $this->generationAudit->syncRun($state->run, [
                'quality_scorecard' => $scorecard,
                'creative_brief' => (array) data_get($item->ai_meta, 'creative_brief', []),
                'identity_validation' => $identityValidation,
                'publish_gate' => $publishGate,
            ]);
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $meta['identity_validation'] = $identityValidation;
            $meta['publish_gate'] = $publishGate;
            $item->ai_meta = $meta;
            $this->qualityScorecard->storeOnContentItem($item, $scorecard, $state->run);
            $job->updateGenerationAuditMeta($item, $state->run?->id, 'failed', [
                'failed_at' => now()->toDateTimeString(),
                'quality_scorecard_status' => (string) ($scorecard['publish_readiness_status'] ?? ''),
                'publish_gate_decision' => (string) ($publishGate['decision'] ?? ''),
            ]);
            $item->save();
            $job->notifyAiFailure($item, $this->workspaceNotifications, (string) $item->ai_error);

            return;
        }

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $job->markFeedbackRequestAsApplied($item);
        $runMetrics = $this->generationMetrics->buildRunMetrics($item, $state->run);
        $state->run = $this->generationAudit->completeRun($state->run, [
            'status' => 'succeeded',
            'effective_output' => $job->buildRunEffectiveOutput($item),
            'result_summary' => $job->buildRunResultSummary($item),
            'overlay_meta' => (array) data_get($item->ai_meta, 'overlay_meta', []),
            'storyboard_meta' => (array) data_get($item->ai_meta, 'storyboard_meta', []),
            'version_meta' => $job->generationVersionMeta(
                is_array($item->ai_meta) ? $item->ai_meta : []
            ),
        ] + $runMetrics);
        $scorecard = $this->qualityScorecard->buildForContentItem($item, $state->run);
        $identityValidation = $this->identityGuard->validateFinal($item, $state->run);
        $publishGate = $this->publishReadinessGate->decideForContentItem($item, $state->run, $scorecard);
        $state->run = $this->generationAudit->syncRun($state->run, [
            'quality_scorecard' => $scorecard,
            'creative_brief' => (array) data_get($item->ai_meta, 'creative_brief', []),
            'identity_validation' => $identityValidation,
            'publish_gate' => $publishGate,
        ]);
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['identity_validation'] = $identityValidation;
        $meta['publish_gate'] = $publishGate;
        $item->ai_meta = $meta;
        $this->qualityScorecard->storeOnContentItem($item, $scorecard, $state->run);
        $job->updateGenerationAuditMeta($item, $state->run?->id, 'succeeded', [
            'completed_at' => now()->toDateTimeString(),
            'quality_scorecard_status' => (string) ($scorecard['publish_readiness_status'] ?? ''),
            'publish_gate_decision' => (string) ($publishGate['decision'] ?? ''),
        ]);
        $item->save();
        $job->notifyAiSuccess($item, $this->workspaceNotifications);
    }
}
