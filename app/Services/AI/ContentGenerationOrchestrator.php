<?php

namespace App\Services\AI;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Services\AI\Pipeline\BuildGenerationContextStep;
use App\Services\AI\Pipeline\BuildVisualPromptStep;
use App\Services\AI\Pipeline\GenerateBaseTextStep;
use App\Services\AI\Pipeline\GenerateVisualAssetStep;
use App\Services\AI\Pipeline\GenerationPipelineState;
use App\Services\AI\Pipeline\PersistGenerationOutputsStep;
use App\Services\AI\Pipeline\ResolveProviderMatrixStep;
use App\Services\GenerationAuditService;
use App\Services\GenerationMetricsService;
use App\Services\Notification\WorkspaceNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the AI content generation pipeline.
 *
 * Responsible for:
 *  - Pipeline step sequencing (context → providers → text → visual prompt → visual asset → persist)
 *  - Progress tracking (touchGenerationRuntime)
 *  - Demo-mode short-circuit path
 *  - Audit completion (run + scorecard)
 *
 * NOT responsible for:
 *  - Queue metadata (tries, backoff, timeout) → GenerateAiForContentItem
 *  - Guard checks (status, idempotency) → GenerateAiForContentItem
 *  - Distributed lock → GenerateAiForContentItem
 *  - Failure handling (failed() hook) → GenerateAiForContentItem
 *
 * Receives `GenerateAiForContentItem $job` as a helper proxy during the
 * progressive refactor. Helper methods (touchGenerationRuntime, applyDemoPreset,
 * buildRunResultSummary, etc.) will migrate from the job to this class over time.
 */
class ContentGenerationOrchestrator
{
    public function __construct(
        private readonly BuildGenerationContextStep      $buildGenerationContext,
        private readonly ResolveProviderMatrixStep       $resolveProviderMatrix,
        private readonly GenerateBaseTextStep            $generateBaseText,
        private readonly BuildVisualPromptStep           $buildVisualPrompt,
        private readonly GenerateVisualAssetStep         $generateVisualAsset,
        private readonly PersistGenerationOutputsStep    $persistGenerationOutputs,
        private readonly WorkspaceNotificationService    $workspaceNotifications,
        private readonly GenerationAuditService          $generationAudit,
        private readonly GenerationMetricsService        $generationMetrics,
        private readonly GenerationQualityScorecardService $qualityScorecard,
    ) {
    }

    /**
     * Execute the full generation pipeline for a content item.
     *
     * Called by GenerateAiForContentItem::handle() after guard checks and
     * lock acquisition. The job is passed so that helper methods still on
     * the job class (touchGenerationRuntime, applyDemoPreset, etc.) are
     * accessible during the transitional refactor period.
     *
     * @throws \Throwable  — propagated to the job's failed() hook
     */
    public function run(GenerateAiForContentItem $job, ContentItem $item): void
    {
        $job->touchGenerationRuntime($item, 'booting', 'Avvio generazione', 6, [
            'runner' => app()->runningInConsole() ? 'console' : 'web',
        ]);

        $state = GenerationPipelineState::fromItem(
            $item,
            (bool) config('generation.strict_asset_mode', true)
        );

        // ── Step 1: Build generation context (brand, strategy, memory) ────────
        $job->touchGenerationRuntime($item, 'context', 'Analisi strategia e brand', 18);
        $state = $this->buildGenerationContext->handle($job, $state);
        $item  = $state->item;

        // ── Step 2: Resolve provider matrix ───────────────────────────────────
        $job->touchGenerationRuntime($item, 'providers', 'Scelta provider e modello', 28);
        $state = $this->resolveProviderMatrix->handle($job, $state);
        $item  = $state->item;

        // ── Demo short-circuit ─────────────────────────────────────────────────
        if ($job->isDemoMode()) {
            $this->runDemoPath($job, $item, $state);
            return;
        }

        // ── Step 3: Generate base text (caption, copy structure) ──────────────
        $job->touchGenerationRuntime($item, 'text', 'Scrittura copy e struttura', 42);
        $state = $this->generateBaseText->handle($job, $state);
        $item  = $state->item;

        // ── Step 4: Build visual prompt ───────────────────────────────────────
        $job->touchGenerationRuntime($item, 'prompt', 'Preparazione direzione visuale', 58);
        $state = $this->buildVisualPrompt->handle($job, $state);
        $item  = $state->item;

        // ── Step 5: Generate visual asset (image / video) ─────────────────────
        $job->touchGenerationRuntime($item, 'visual', 'Generazione visual', 76);
        $state = $this->generateVisualAsset->handle($job, $state);
        $item  = $state->item;

        // ── Step 6: Persist outputs ────────────────────────────────────────────
        $job->touchGenerationRuntime($item, 'finalizing', 'Salvataggio output finale', 92);
        $this->persistGenerationOutputs->handle($job, $state);

        $item->refresh();
        $job->markGenerationRuntimeFinished(
            $item,
            (string) ($item->ai_status ?: 'done'),
            $item->ai_status === 'done' ? 'Contenuto pronto' : 'Serve una verifica'
        );
        $item->save();

        $job->notifyAiSuccess($item, $this->workspaceNotifications);
    }

    // ── Demo path ────────────────────────────────────────────────────────────

    private function runDemoPath(
        GenerateAiForContentItem $job,
        ContentItem $item,
        GenerationPipelineState $state
    ): void {
        $demoAttempt = $this->generationAudit->startAttempt($state->run, 'demo_preset', [
            'type'               => 'system',
            'stage'              => 'demo_preset',
            'provider_requested' => 'local_demo',
            'provider_effective' => 'local_demo',
            'model_requested'    => 'demo_preset_v1',
            'model_effective'    => 'demo_preset_v1',
            'input_summary'      => [
                'format'   => (string) $item->format,
                'platform' => (string) $item->platform,
            ],
        ]);

        $job->applyDemoPreset(
            $item,
            (array) $state->get('tenant_profile', []),
            (array) $state->get('item_brain', []),
            $state->meta
        );

        $this->generationAudit->completeAttempt($demoAttempt, [
            'status'          => 'succeeded',
            'output_summary'  => $job->buildRunResultSummary($item),
            'tenant_id'       => (int) $item->tenant_id,
            'final_provider'  => 'local_demo',
            'failure_mode'    => null,
        ]);

        $runMetrics  = $this->generationMetrics->buildRunMetrics($item, $state->run);
        $state->run  = $this->generationAudit->completeRun($state->run, [
            'status'           => 'succeeded',
            'effective_output' => $job->buildRunEffectiveOutput($item),
            'result_summary'   => $job->buildRunResultSummary($item),
            'overlay_meta'     => (array) data_get($item->ai_meta, 'overlay_meta', []),
            'storyboard_meta'  => (array) data_get($item->ai_meta, 'storyboard_meta', []),
            'version_meta'     => $job->generationVersionMeta(
                is_array($item->ai_meta) ? $item->ai_meta : []
            ),
        ] + $runMetrics);

        $scorecard  = $this->qualityScorecard->buildForContentItem($item, $state->run);
        $state->run = $this->generationAudit->syncRun($state->run, [
            'quality_scorecard' => $scorecard,
        ]);
        $this->qualityScorecard->storeOnContentItem($item, $scorecard, $state->run);
        $job->updateGenerationAuditMeta($item, $state->run?->id, 'succeeded', [
            'completed_at'                => now()->toDateTimeString(),
            'quality_scorecard_status'    => (string) ($scorecard['publish_readiness_status'] ?? ''),
        ]);
        $job->markGenerationRuntimeFinished($item, 'done', 'Contenuto pronto');
        $item->save();

        $job->notifyAiSuccess($item, $this->workspaceNotifications);
    }
}
