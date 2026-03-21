<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Services\AI\TenantContentIntelligenceService;
use App\Services\GenerationAuditService;
use App\Services\MemoryBuilderService;
use Illuminate\Support\Str;

class BuildGenerationContextStep
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly TenantContentIntelligenceService $tenantContentIntelligence,
        private readonly GenerationAuditService $generationAudit
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): GenerationPipelineState
    {
        $item = $state->item;
        $item->ai_status = 'pending';
        $item->ai_error = null;
        $item->save();

        $meta = $state->meta;
        $run = $this->generationAudit->startRun($item, $job->runKey, [
            'requested_provider_matrix' => $job->requestedProviderMatrixSnapshot($meta),
            'requested_output' => $job->requestedOutputSummary($item, $meta),
            'version_meta' => $job->generationVersionMeta($meta),
        ]);
        $job->updateGenerationAuditMeta($item, $run?->id, 'running');
        $meta = is_array($item->ai_meta) ? $item->ai_meta : $meta;

        $liveBrandAssets = $job->loadBrandAssetsFromDb((int) $item->tenant_id);
        $meta['brand_assets'] = $job->mergeBrandAssets((array) data_get($meta, 'brand_assets', []), $liveBrandAssets);

        $strategy = data_get($meta, 'strategy', $item->plan?->strategy ?? []);
        $itemBrain = data_get($meta, 'item_brain', []);
        $tenantProfile = data_get($meta, 'tenant_profile', data_get($meta, 'brand', []));
        $memorySummary = $this->memoryBuilder->buildForTenant((int) $item->tenant_id, 40);
        $activeFeedbackRequest = $job->normalizeFeedbackRequest((array) data_get($meta, 'feedback_loop.active_request', []));
        $assetVariables = $job->resolveAssetVariableContext((int) $item->tenant_id, $meta, $strategy);
        $assetIdentity = $job->resolveAssetIdentityContext($meta, $assetVariables);
        $briefSeed = trim((string) ($item->caption ?: data_get($meta, 'manual_brief', $item->title ?: '')));
        $tenantIntelligence = $this->tenantContentIntelligence->buildForGeneration(
            (int) $item->tenant_id,
            $briefSeed,
            (string) $item->format,
            $item->platforms()
        );

        $meta['memory_summary'] = $memorySummary;
        $meta['image_provider'] = $job->resolveImageProvider($meta);
        $meta['asset_variables'] = $assetVariables;
        $meta['asset_variables_catalog'] = (array) ($assetVariables['catalog'] ?? []);
        $meta['asset_identity'] = $assetIdentity;
        $meta['knowledge_pack'] = (array) ($tenantIntelligence['knowledge_pack'] ?? []);
        $meta['examples'] = (array) ($tenantIntelligence['examples'] ?? []);
        $meta['negative_examples'] = (array) ($tenantIntelligence['negative_examples'] ?? []);
        $meta['feedback_signals'] = (array) ($tenantIntelligence['feedback_signals'] ?? []);
        $meta['strategy_snapshot'] = [
            'strategy_id' => data_get($strategy, 'strategy_id'),
            'strategy_updated_at' => data_get($strategy, 'strategy_updated_at'),
            'strategy_locked' => (bool) data_get($strategy, 'strategy_locked', false),
            'analysis_framework' => (array) data_get($strategy, 'analysis_framework', []),
            'visual_system' => (array) data_get($strategy, 'visual_system', []),
            'publishing_system' => (array) data_get($strategy, 'publishing_system', []),
            'strategy_notes' => (string) data_get($strategy, 'strategy_notes', ''),
            'captured_at' => now()->toDateTimeString(),
        ];

        $recentCaptions = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_caption')
            ->whereIn('ai_status', ['done', 'pending'])
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('ai_caption')
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        $planContext = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get(['title', 'ai_caption']);

        $planTitles = $planContext
            ->pluck('title')
            ->filter()
            ->map(fn ($title) => Str::limit((string) $title, 120, ''))
            ->values()
            ->all();

        $planCaptions = $planContext
            ->pluck('ai_caption')
            ->filter()
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        $state->run = $run;
        $state->meta = $meta;
        $state->put('strategy', $strategy)
            ->put('item_brain', $itemBrain)
            ->put('tenant_profile', $tenantProfile)
            ->put('memory_summary', $memorySummary)
            ->put('active_feedback_request', $activeFeedbackRequest)
            ->put('asset_variables', $assetVariables)
            ->put('asset_identity', $assetIdentity)
            ->put('brief_seed', $briefSeed)
            ->put('tenant_intelligence', $tenantIntelligence)
            ->put('recent_captions', $recentCaptions)
            ->put('plan_titles', $planTitles)
            ->put('plan_captions', $planCaptions);

        $state->syncMetaToItem();
        $item->save();

        return $state;
    }
}
