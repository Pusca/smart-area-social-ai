<?php

namespace App\Services\AI\Pipeline;

use App\Data\ContentGenerationInput;
use App\Jobs\GenerateAiForContentItem;
use App\Models\AlterEgo;
use App\Models\AssetVariable;
use App\Models\ContentItem;
use App\Models\TenantProfile;
use App\Services\AI\IdentityGuard;
use App\Services\AI\TenantContentIntelligenceService;
use App\Services\AlterEgoService;
use App\Services\Editorial\CreativeBriefCompiler;
use App\Services\Editorial\TrendBriefService;
use App\Services\GenerationAuditService;
use App\Services\IdentityAssetService;
use App\Services\Learning\TenantLearningLoopService;
use App\Services\MemoryBuilderService;
use App\Services\Trends\TenantTrendSelectorService;
use App\Services\Trends\TrendContentTranslatorService;
use App\Support\VisualModePresets;
use Illuminate\Support\Str;

class BuildGenerationContextStep
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly TenantContentIntelligenceService $tenantContentIntelligence,
        private readonly GenerationAuditService $generationAudit,
        private readonly TrendBriefService $trendBriefService,
        private readonly TenantTrendSelectorService $trendSelector,
        private readonly TrendContentTranslatorService $trendTranslator,
        private readonly TenantLearningLoopService $tenantLearningLoopService,
        private readonly CreativeBriefCompiler $creativeBriefCompiler,
        private readonly AlterEgoService $alterEgoService,
        private readonly IdentityAssetService $identityAssetService,
        private readonly IdentityGuard $identityGuard,
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

        // ── Visual mode — letto fresh dal DB (sempre aggiornato anche senza rigenerare il piano) ──
        $visualMode = (string) data_get($meta, 'visual_mode', '');
        if ($visualMode === '') {
            $freshProfile = TenantProfile::query()
                ->where('tenant_id', (int) $item->tenant_id)
                ->value('overlay_preferences');
            $freshOverlay = is_array($freshProfile) ? $freshProfile : (is_string($freshProfile) ? (json_decode($freshProfile, true) ?? []) : []);
            $visualMode = (string) ($freshOverlay['visual_mode'] ?? VisualModePresets::DEFAULT_MODE);
        }
        $visualModePreset = VisualModePresets::getOrDefault($visualMode);

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

        // ── Trend Engine: selezione segnali per item + traduzione in idee editoriali ──
        // Feature-flagged: se disabilitato, passa array vuoto senza chiamate GPT.
        $trendDrivenIdeas = [];
        if ((bool) config('social_manager.features.trend_engine_v1', true) && !empty($trendBrief)) {
            $snapshotId       = (int) data_get($trendBrief, 'source_snapshot_id', 0);
            $selectedSignals  = $this->trendSelector->selectFor($item, $snapshotId);
            if (!empty($selectedSignals)) {
                $trendDrivenIdeas = $this->trendTranslator->translate(
                    $selectedSignals,
                    $tenantProfile,
                    $item
                );
            }
        }

        // Creative brief sempre attivo — non dipende da feature flag
        $creativeBrief = $this->creativeBriefCompiler->compileForContentItem($item, [
            'strategy' => $strategy,
            'tenant_profile' => $tenantProfile,
            'item_brain' => $itemBrain,
            'asset_identity' => $assetIdentity,
            'memory_summary' => $memorySummary,
            'trend_brief' => $trendBrief,
            'trend_driven_ideas' => $trendDrivenIdeas,
            'tenant_learning' => $tenantLearning,
            'brief_seed' => $briefSeed,
            'visual_mode' => $visualMode,
        ])->toArray();

        // ── Hard constraints — estratti da asset_variables e promossi in testa al context ──
        // GPT legge hard_constraints PRIMA di tutto il resto: identita reali non negoziabili.
        $hardConstraints = $this->buildHardConstraints($assetVariables, $assetIdentity);

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
        // La piattaforma è ricavata dall'item (es. "instagram") per scegliere
        // il prompt adattato corretto.
        $primaryPlatform = strtolower(explode(',', (string) ($item->platform ?? ''))[0]);
        $alterEgoContext = $this->resolveAlterEgoContext($item, $meta, $primaryPlatform);

        // ── IdentityAsset context (nuovo sistema) ──────────────────────────────
        // Carica gli IdentityAsset collegati alle AssetVariable attive del tenant
        // e all'AlterEgo corrente. Usato dal BuildVisualPromptStep per iniettare
        // prompt visivi compilati e path di riferimento canonici.
        $identityAssetContext = $this->resolveIdentityAssetContext(
            tenantId: (int) $item->tenant_id,
            provider: $primaryPlatform,
            alterEgoContext: $alterEgoContext,
            strictMode: $state->strictAssetMode,
        );

        $state->run = $run;
        $state->run = $this->generationAudit->syncRun($state->run, [
            'creative_brief'          => $creativeBrief,
            'visual_mode'             => $visualMode,
            'hard_constraints_count'  => count($hardConstraints),
            'identity_assets_count'   => count($identityAssetContext['slots'] ?? []),
        ]);
        $state->meta = $meta;
        $state->meta['tenant_learning'] = $tenantLearning;
        $state->meta['trend_brief'] = $trendBrief;
        $state->meta['trend_driven_ideas'] = $trendDrivenIdeas;
        $state->meta['creative_brief'] = $creativeBrief;
        $state->meta['visual_mode'] = $visualMode;
        $state->meta['visual_mode_preset'] = $visualModePreset;
        $state->meta['hard_constraints'] = $hardConstraints;
        if (!empty($alterEgoContext)) {
            $state->meta['alter_ego'] = $alterEgoContext;
        }
        if (!empty($identityAssetContext['slots'])) {
            $state->meta['identity_assets'] = $identityAssetContext;
        }
        $state->put('strategy', $strategy)
            ->put('item_brain', $itemBrain)
            ->put('tenant_profile', $tenantProfile)
            ->put('memory_summary', $memorySummary)
            ->put('tenant_learning', $tenantLearning)
            ->put('trend_brief', $trendBrief)
            ->put('trend_driven_ideas', $trendDrivenIdeas)
            ->put('creative_brief', $creativeBrief)
            ->put('active_feedback_request', $activeFeedbackRequest)
            ->put('asset_variables', $assetVariables)
            ->put('asset_identity', $assetIdentity)
            ->put('identity_assets', $identityAssetContext)
            ->put('brief_seed', $briefSeed)
            ->put('tenant_intelligence', $tenantIntelligence)
            ->put('recent_captions', $recentCaptions)
            ->put('plan_titles', $planTitles)
            ->put('plan_captions', $planCaptions)
            ->put('alter_ego', $alterEgoContext)
            ->put('visual_mode', $visualMode)
            ->put('visual_mode_preset', $visualModePreset)
            ->put('hard_constraints', $hardConstraints);

        // ── Build typed DTO for downstream steps ───────────────────────────
        $state->input = ContentGenerationInput::fromItemAndMeta($item, $state->meta, $state->strictAssetMode);

        $state->syncMetaToItem();
        $item->save();

        return $state;
    }

    /**
     * Estrae hard constraints non negoziabili da asset_variables e asset_identity.
     * Restituisce un array flat di stringhe da iniettare in testa al context GPT.
     *
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $assetIdentity
     * @return list<string>
     */
    private function buildHardConstraints(array $assetVariables, array $assetIdentity): array
    {
        $constraints = [];

        // ── Personas in asset_variables ──────────────────────────────────────
        $personas = (array) data_get($assetVariables, 'personas', []);
        foreach ($personas as $persona) {
            if (!is_array($persona)) {
                continue;
            }
            $name = (string) data_get($persona, 'name', '');
            $role = (string) data_get($persona, 'role', '');
            $immutableTraits = (array) data_get($persona, 'immutable_traits', []);
            $promptNotes = (string) data_get($persona, 'prompt_notes', '');
            $lookNotes = (string) data_get($persona, 'look_notes', '');

            if ($name !== '') {
                $label = $name . ($role !== '' ? " ({$role})" : '');
                foreach ($immutableTraits as $trait) {
                    if (is_string($trait) && $trait !== '') {
                        $constraints[] = "PERSONA {$label} — tratto immutabile: {$trait}";
                    }
                }
                if ($promptNotes !== '') {
                    $constraints[] = "PERSONA {$label} — regola operativa: {$promptNotes}";
                }
                if ($lookNotes !== '') {
                    $constraints[] = "PERSONA {$label} — look obbligatorio: {$lookNotes}";
                }
            }
        }

        // ── asset_identity locked_elements e consistency_mode ────────────────
        $lockedElements = (array) data_get($assetIdentity, 'locked_elements', []);
        foreach ($lockedElements as $element) {
            if (is_string($element) && $element !== '') {
                $constraints[] = "ELEMENTO BLOCCATO (non modificabile): {$element}";
            }
        }

        $consistencyMode = (string) data_get($assetIdentity, 'consistency_mode', '');
        if ($consistencyMode === 'strict') {
            $constraints[] = "CONSISTENCY_MODE=strict: usa solo editing e variazioni controllate. Non cambiare soggetto principale, ambiente o styling.";
        }

        // ── Catalog level immutable constraints ──────────────────────────────
        $catalogConstraints = (array) data_get($assetVariables, 'catalog_constraints', []);
        foreach ($catalogConstraints as $constraint) {
            if (is_string($constraint) && $constraint !== '') {
                $constraints[] = "VINCOLO CATALOGO: {$constraint}";
            }
        }

        return array_values($constraints);
    }

    /**
     * Carica il contesto alter ego per questa generazione.
     * Priorità: alter_ego_id sulla colonna item > ai_meta['alter_ego_id'] > default tenant.
     * Il persona_prompt restituito è adattato per la piattaforma target, se esiste
     * un adattamento configurato.
     *
     * Se l'AlterEgo ha un IdentityAsset collegato, aggiunge:
     * - identity_asset_id: id del modello
     * - identity_prompt: prompt visivo compilato dell'identità
     * - canonical_path: path assoluto dell'immagine canonica (se presente)
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function resolveAlterEgoContext(ContentItem $item, array $meta, string $platform = ''): array
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

        // Usa prompt adattato per la piattaforma se disponibile
        $personaPrompt = $platform !== ''
            ? $this->alterEgoService->buildPlatformAdaptedPrompt($alterEgo, $platform)
            : (string) ($alterEgo->persona_prompt_cache ?? '');

        $visualRefs = array_values(array_filter(array_map(
            'strval',
            (array) ($alterEgo->visual_references ?? [])
        )));

        $context = [
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
            'platform_adapted'   => $platform !== '',
            'platform'           => $platform,
            'persona_prompt'     => $personaPrompt,
            'visual_references'  => $visualRefs,
            'identity_asset_id'  => null,
            'identity_prompt'    => null,
            'identity_canonical_path' => null,
        ];

        // ── IdentityAsset dell'AlterEgo (se collegato) ────────────────────────
        if ($alterEgo->identity_asset_id !== null) {
            $alterEgo->loadMissing('identityAsset');
            $identity = $alterEgo->identityAsset;
            if ($identity && $identity->is_active) {
                $identity->loadMissing('canonicalMedia', 'referenceMedia');
                $canonicalPath = $identity->canonicalPath();

                // Aggiunge il canonical path ai visual_references se non già presente
                if ($canonicalPath !== null && ! in_array($canonicalPath, $visualRefs, true)) {
                    array_unshift($context['visual_references'], $canonicalPath);
                }

                $context['identity_asset_id']       = $identity->id;
                $context['identity_prompt']          = $this->identityAssetService->compiledPrompt($identity);
                $context['identity_canonical_path']  = $canonicalPath;
            }
        }

        return $context;
    }

    /**
     * Risolve gli IdentityAsset collegati alle AssetVariable attive del tenant.
     *
     * Restituisce un array strutturato con un "slot" per ogni IdentityAsset trovato,
     * contenente: prompt compilato, path canonici/reference, risultato preflight.
     *
     * Se nessuna AssetVariable ha identity_asset_id valorizzato, ritorna array vuoto
     * → zero impatto sul pipeline per i tenant che non usano il nuovo sistema.
     *
     * @param  array<string, mixed>  $alterEgoContext
     * @return array<string, mixed>
     */
    private function resolveIdentityAssetContext(
        int $tenantId,
        string $provider,
        array $alterEgoContext,
        bool $strictMode,
    ): array {
        $slots = [];
        $seenIds = [];

        // ── Da AssetVariable ──────────────────────────────────────────────────
        $variables = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('identity_asset_id')
            ->with(['identityAsset.canonicalMedia', 'identityAsset.referenceMedia'])
            ->get();

        foreach ($variables as $variable) {
            $identity = $variable->identityAsset;
            if (! $identity || ! $identity->is_active || isset($seenIds[$identity->id])) {
                continue;
            }
            $seenIds[$identity->id] = true;

            $slots[] = $this->buildIdentityAssetSlot(
                identity: $identity,
                source: 'asset_variable',
                sourceId: (int) $variable->id,
                sourceSlug: (string) $variable->slug,
                provider: $provider,
                strictMode: $strictMode,
            );
        }

        // ── Da AlterEgo (se ha IdentityAsset e non già aggiunto) ─────────────
        $alterEgoIdentityId = (int) ($alterEgoContext['identity_asset_id'] ?? 0);
        if ($alterEgoIdentityId > 0 && ! isset($seenIds[$alterEgoIdentityId])) {
            $identity = \App\Models\IdentityAsset::query()
                ->where('id', $alterEgoIdentityId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->with(['canonicalMedia', 'referenceMedia'])
                ->first();

            if ($identity) {
                $seenIds[$identity->id] = true;
                $slots[] = $this->buildIdentityAssetSlot(
                    identity: $identity,
                    source: 'alter_ego',
                    sourceId: (int) ($alterEgoContext['id'] ?? 0),
                    sourceSlug: 'alter_ego_' . ($alterEgoContext['id'] ?? ''),
                    provider: $provider,
                    strictMode: $strictMode,
                );
            }
        }

        if (empty($slots)) {
            return [];
        }

        return [
            'version'    => 'identity_asset_v1',
            'slots'      => $slots,
            'has_locked' => collect($slots)->some(fn (array $s) => (bool) ($s['locked'] ?? false)),
        ];
    }

    /**
     * Costruisce il dati di un singolo slot IdentityAsset per il context.
     *
     * @return array<string, mixed>
     */
    private function buildIdentityAssetSlot(
        \App\Models\IdentityAsset $identity,
        string $source,
        int $sourceId,
        string $sourceSlug,
        string $provider,
        bool $strictMode,
    ): array {
        $canonicalPath  = $identity->canonicalPath();
        $referencePaths = $identity->referencePathsForProvider($provider ?: 'default');

        $preflight = $this->identityGuard->preflightFromIdentityAsset(
            $identity,
            $provider,
            ['strict_asset_mode' => $strictMode || $identity->locked_visual_identity]
        );

        return [
            'source'             => $source,           // 'asset_variable' | 'alter_ego'
            'source_id'          => $sourceId,
            'source_slug'        => $sourceSlug,
            'identity_asset_id'  => $identity->id,
            'identity_type'      => $identity->type,
            'identity_name'      => $identity->name,
            'identity_prompt'    => $this->identityAssetService->compiledPrompt($identity),
            'canonical_path'     => $canonicalPath,
            'reference_paths'    => $referencePaths,
            'locked'             => $identity->locked_visual_identity,
            'preflight'          => $preflight,
        ];
    }
}
