<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Models\AlterEgo;
use App\Models\ContentItem;
use App\Services\AlterEgoService;
use App\Services\Editorial\CreativeBriefCompiler;
use App\Services\Editorial\TrendBriefService;
use App\Services\AI\TenantContentIntelligenceService;
use App\Services\GenerationAuditService;
use App\Services\Learning\TenantLearningLoopService;
use App\Services\MemoryBuilderService;
use Illuminate\Support\Str;

class BuildGenerationContextStep
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly TenantContentIntelligenceService $tenantContentIntelligence,
        private readonly GenerationAuditService $generationAudit,
        private readonly TrendBriefService $trendBriefService,
        private readonly TenantLearningLoopService $tenantLearningLoopService,
        private readonly CreativeBriefCompiler $creativeBriefCompiler,
        private readonly AlterEgoService $alterEgoService
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
            'creative_direction' => (array) data_get($strategy, 'creative_direction', []),
            'trend_intelligence' => (array) data_get($strategy, 'trend_intelligence', []),
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

        $tenantLearning = (bool) config('social_manager.features.tenant_learning_v1', true)
            ? $this->tenantLearningLoopService->refreshForTenant((int) $item->tenant_id)
            : (array) data_get($meta, 'tenant_learning', data_get($strategy, 'tenant_learning', []));
        $trendBrief = (bool) config('social_manager.features.trend_brief_v1', true)
            ? $this->trendBriefService->getBriefForTenant(
                (int) $item->tenant_id,
                null,
                $strategy,
                [
                    'strategy' => $strategy,
                    'learning_preferences' => $tenantLearning,
                    'platforms' => $item->platforms(),
                    'formats' => [(string) $item->format],
                    'asset_readiness' => (array) data_get($strategy, 'analysis_framework.asset_readiness', []),
                ]
            )
            : (array) data_get($meta, 'trend_brief', data_get($strategy, 'trend_brief', []));
        $creativeBrief = (bool) config('social_manager.features.creative_brief_v1', true)
            ? $this->creativeBriefCompiler->compileForContentItem($item, [
                'strategy' => $strategy,
                'tenant_profile' => $tenantProfile,
                'item_brain' => $itemBrain,
                'asset_identity' => $assetIdentity,
                'memory_summary' => $memorySummary,
                'trend_brief' => $trendBrief,
                'tenant_learning' => $tenantLearning,
                'brief_seed' => $briefSeed,
            ])->toArray()
            : (array) data_get($meta, 'creative_brief', []);

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

        // ── Alter Ego ──────────────────────────────────────────────────────────
        // Priorità: alter_ego_id sull'item → id in ai_meta → default del tenant.
        $alterEgoContext = $this->resolveAlterEgoContext($item, $meta);

        $state->run = $run;
        $state->run = $this->generationAudit->syncRun($state->run, [
            'creative_brief' => $creativeBrief,
        ]);
        $state->meta = $meta;
        $state->meta['tenant_learning'] = $tenantLearning;
        $state->meta['trend_brief'] = $trendBrief;
        $state->meta['creative_brief'] = $creativeBrief;
        if (!empty($alterEgoContext)) {
            $state->meta['alter_ego'] = $alterEgoContext;
        }
        $state->put('strategy', $strategy)
            ->put('item_brain', $itemBrain)
            ->put('tenant_profile', $tenantProfile)
            ->put('memory_summary', $memorySummary)
            ->put('tenant_learning', $tenantLearning)
            ->put('trend_brief', $trendBrief)
            ->put('creative_brief', $creativeBrief)
            ->put('active_feedback_request', $activeFeedbackRequest)
            ->put('asset_variables', $assetVariables)
            ->put('asset_identity', $assetIdentity)
            ->put('brief_seed', $briefSeed)
            ->put('tenant_intelligence', $tenantIntelligence)
            ->put('recent_captions', $recentCaptions)
            ->put('plan_titles', $planTitles)
            ->put('plan_captions', $planCaptions)
            ->put('alter_ego', $alterEgoContext);

        $state->syncMetaToItem();
        $item->save();

        return $state;
    }

    /**
     * Carica il contesto alter ego per questa generazione.
     * Priorità: alter_ego_id sulla colonna item > ai_meta['alter_ego_id'] > default tenant.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function resolveAlterEgoContext(ContentItem $item, array $meta): array
    {
        $alterEgoId = (int) ($item->alter_ego_id ?? 0) ?: (int) data_get($meta, 'alter_ego_id', 0);

        $query = AlterEgo::query()
            ->where('tenant_id', (int) $item->tenant_id)
            ->where('is_active', true);

        $alterEgo = $alterEgoId > 0
            ? (clone $query)->find($alterEgoId)
            : (clone $query)->where('is_default', true)->first();

        if ($alterEgo === null) {
            return [];
        }

        // Assicura cache compilata
        if (empty($alterEgo->persona_prompt_cache)) {
            $this->alterEgoService->recompile($alterEgo);
            $alterEgo->refresh();
        }

        return [
            'id'                 => $alterEgo->id,
            'name'               => $alterEgo->name,
            'archetype'          => $alterEgo->archetype,
            'tone'               => $alterEgo->tone,
            'sentence_style'     => $alterEgo->sentence_style,
            'vocabulary_level'   => $alterEgo->vocabulary_level,
            'signature_phrases'  => $alterEgo->signature_phrases ?? [],
            'topics_owned'       => $alterEgo->topics_owned ?? [],
            'topics_avoided'     => $alterEgo->topics_avoided ?? [],
            'unique_perspective' => $alterEgo->unique_perspective,
            'audience_role'      => $alterEgo->audience_role,
            'cta_style'          => $alterEgo->cta_style,
            'persona_prompt'     => (string) ($alterEgo->persona_prompt_cache ?? ''),
        ];
    }
}
