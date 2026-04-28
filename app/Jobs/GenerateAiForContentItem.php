<?php

namespace App\Jobs;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Services\AI\AiProviderMatrixService;
use App\Services\AssetIdentityService;
use App\Services\AssetVariableService;
use App\Services\AI\ContentAlignmentService;
use App\Services\AI\GenerationQualityScorecardService;
use App\Services\AI\GenerationVersionRegistry;
use App\Services\AI\Pipeline\BuildGenerationContextStep;
use App\Services\AI\Pipeline\BuildVisualPromptStep;
use App\Services\AI\Pipeline\GenerateBaseTextStep;
use App\Services\AI\Pipeline\GenerateVisualAssetStep;
use App\Services\AI\Pipeline\GenerationPipelineState;
use App\Services\AI\Pipeline\PersistGenerationOutputsStep;
use App\Services\AI\Pipeline\ResolveProviderMatrixStep;
use App\Services\AI\ProviderCapabilityRegistry;
use App\Services\AI\TenantContentIntelligenceService;
use App\Services\GenerationAuditService;
use App\Services\GenerationMetricsService;
use App\Services\GoogleVeoService;
use App\Services\MemoryBuilderService;
use App\Support\ImagePromptRealismGuard;
use App\Services\KlingService;
use App\Services\NanoBananaService;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\OpenAiService;
use App\Services\RunwayService;
use App\Services\SpeechSynthesisService;
use App\Support\ImageProviderResolver;
use App\Support\PublicMediaUrl;
use App\Support\VideoProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateAiForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    /**
     * Numero massimo di tentativi. Impostato a 3 per tollerare errori transitori
     * (timeout API, connessioni instabili). failOnTimeout = true impedisce retry
     * su timeout del job, che sono attesi per video lunghi.
     */
    public int $tries = 3;

    /**
     * Backoff progressivo tra i retry: 30s dopo il primo fallimento, 60s dopo il secondo.
     * Evita di sovraccaricare i provider AI già sotto stress.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60];

    public bool $failOnTimeout = true;
    public string $runKey;

    public function __construct(public int $contentItemId, ?string $runKey = null)
    {
        $this->runKey = trim((string) $runKey) !== ''
            ? trim((string) $runKey)
            : Str::uuid()->toString();
    }

    public function handle(
        BuildGenerationContextStep $buildGenerationContext,
        ResolveProviderMatrixStep $resolveProviderMatrix,
        GenerateBaseTextStep $generateBaseText,
        BuildVisualPromptStep $buildVisualPrompt,
        GenerateVisualAssetStep $generateVisualAsset,
        PersistGenerationOutputsStep $persistGenerationOutputs,
        WorkspaceNotificationService $workspaceNotifications,
        GenerationAuditService $generationAudit,
        GenerationMetricsService $generationMetrics,
        GenerationQualityScorecardService $qualityScorecard
    ): void
    {
        $item = ContentItem::query()->with('plan')->findOrFail($this->contentItemId);
        $status = strtolower(trim((string) ($item->ai_status ?? '')));
        if (!in_array($status, ['queued', 'pending'], true)) {
            Log::info('GenerateAiForContentItem skipped because content item is no longer active', [
                'content_item_id' => $item->id,
                'run_key' => $this->runKey,
                'ai_status' => $status,
            ]);

            return;
        }

        $lockKey = $this->processingLockKey();

        /**
         * Cache::lock() è compatibile con Redis (lock distribuito) e database driver (dev).
         * A differenza di Cache::add(), gestisce l'owner token in modo atomico e ha
         * release() sicura anche se il lock è già scaduto.
         * Timeout: max(1800, job_timeout + 300) per garantire che il lock superi
         * sempre la durata massima del job.
         */
        $lock = Cache::lock($lockKey, max(1800, $this->timeout + 300));

        if (!$lock->get()) {
            Log::info('GenerateAiForContentItem skipped because another runner already owns the content item lock', [
                'content_item_id' => $item->id,
                'run_key' => $this->runKey,
                'ai_status' => $status,
            ]);

            return;
        }

        $this->touchGenerationRuntime($item, 'booting', 'Avvio generazione', 6, [
            'runner' => app()->runningInConsole() ? 'console' : 'web',
        ]);

        $state = GenerationPipelineState::fromItem(
            $item,
            (bool) config('generation.strict_asset_mode', true)
        );

        try {
            $this->touchGenerationRuntime($item, 'context', 'Analisi strategia e brand', 18);
            $state = $buildGenerationContext->handle($this, $state);
            $item = $state->item;

            $this->touchGenerationRuntime($item, 'providers', 'Scelta provider e modello', 28);
            $state = $resolveProviderMatrix->handle($this, $state);
            $item = $state->item;

            if ($this->isDemoMode()) {
                $demoAttempt = $generationAudit->startAttempt($state->run, 'demo_preset', [
                    'type' => 'system',
                    'stage' => 'demo_preset',
                    'provider_requested' => 'local_demo',
                    'provider_effective' => 'local_demo',
                    'model_requested' => 'demo_preset_v1',
                    'model_effective' => 'demo_preset_v1',
                    'input_summary' => [
                        'format' => (string) $item->format,
                        'platform' => (string) $item->platform,
                    ],
                ]);

                $this->applyDemoPreset(
                    $item,
                    (array) $state->get('tenant_profile', []),
                    (array) $state->get('item_brain', []),
                    $state->meta
                );

                $generationAudit->completeAttempt($demoAttempt, [
                    'status' => 'succeeded',
                    'output_summary' => $this->buildRunResultSummary($item),
                    'tenant_id' => (int) $item->tenant_id,
                    'final_provider' => 'local_demo',
                    'failure_mode' => null,
                ]);

                $runMetrics = $generationMetrics->buildRunMetrics($item, $state->run);
                $state->run = $generationAudit->completeRun($state->run, [
                    'status' => 'succeeded',
                    'effective_output' => $this->buildRunEffectiveOutput($item),
                    'result_summary' => $this->buildRunResultSummary($item),
                    'overlay_meta' => (array) data_get($item->ai_meta, 'overlay_meta', []),
                    'storyboard_meta' => (array) data_get($item->ai_meta, 'storyboard_meta', []),
                    'version_meta' => $this->generationVersionMeta(
                        is_array($item->ai_meta) ? $item->ai_meta : []
                    ),
                ] + $runMetrics);
                $scorecard = $qualityScorecard->buildForContentItem($item, $state->run);
                $state->run = $generationAudit->syncRun($state->run, [
                    'quality_scorecard' => $scorecard,
                ]);
                $qualityScorecard->storeOnContentItem($item, $scorecard, $state->run);
                $this->updateGenerationAuditMeta($item, $state->run?->id, 'succeeded', [
                    'completed_at' => now()->toDateTimeString(),
                    'quality_scorecard_status' => (string) ($scorecard['publish_readiness_status'] ?? ''),
                ]);
                $this->markGenerationRuntimeFinished($item, 'done', 'Contenuto pronto');
                $item->save();
                $this->notifyAiSuccess($item, $workspaceNotifications);

                return;
            }

            $this->touchGenerationRuntime($item, 'text', 'Scrittura copy e struttura', 42);
            $state = $generateBaseText->handle($this, $state);
            $item = $state->item;

            $this->touchGenerationRuntime($item, 'prompt', 'Preparazione direzione visuale', 58);
            $state = $buildVisualPrompt->handle($this, $state);
            $item = $state->item;

            $this->touchGenerationRuntime($item, 'visual', 'Generazione visual', 76);
            $state = $generateVisualAsset->handle($this, $state);
            $item = $state->item;

            $this->touchGenerationRuntime($item, 'finalizing', 'Salvataggio output finale', 92);
            $persistGenerationOutputs->handle($this, $state);

            $item->refresh();
            $this->markGenerationRuntimeFinished(
                $item,
                (string) ($item->ai_status ?: 'done'),
                $item->ai_status === 'done' ? 'Contenuto pronto' : 'Serve una verifica'
            );
            $item->save();
        } finally {
            // Rilascia il lock distribuito in ogni caso: successo, eccezione o timeout.
            // release() è no-op se il lock è già scaduto — nessun rischio di eccezione.
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        $item = ContentItem::query()->find($this->contentItemId);
        if (!$item) {
            Log::error('GenerateAiForContentItem failed but content item no longer exists', [
                'content_item_id' => $this->contentItemId,
                'run_key' => $this->runKey,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::error('GenerateAiForContentItem failed', [
            'content_item_id' => $item->id,
            'tenant_id' => (int) $item->tenant_id,
            'run_key' => $this->runKey,
            'ai_status_before_failure' => (string) $item->ai_status,
            'error' => $e->getMessage(),
        ]);

        $item->ai_status = 'error';
        if (trim((string) $item->ai_error) === '') {
            $item->ai_error = 'JOB: ' . $e->getMessage();
        }
        $this->markGenerationRuntimeFinished($item, 'error', 'Serve intervento', [
            'last_error' => Str::limit($e->getMessage(), 220, ''),
        ]);
        $existingRun = \App\Models\GenerationRun::query()
            ->where('content_item_id', (int) $item->id)
            ->where('run_key', $this->runKey)
            ->first();
        $runMetrics = app(GenerationMetricsService::class)->buildRunMetrics($item, $existingRun);
        $run = app(GenerationAuditService::class)->failRunByKey(
            (int) $item->id,
            $this->runKey,
            $e,
            [
                'effective_output' => $this->buildRunEffectiveOutput($item),
                'result_summary' => $this->buildRunResultSummary($item),
                'overlay_meta' => (array) data_get($item->ai_meta, 'overlay_meta', []),
                'storyboard_meta' => (array) data_get($item->ai_meta, 'storyboard_meta', []),
                'last_error' => (string) $item->ai_error,
                'version_meta' => $this->generationVersionMeta(
                    is_array($item->ai_meta) ? $item->ai_meta : []
                ),
            ] + $runMetrics
        );
        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);
        $run = app(GenerationAuditService::class)->syncRun($run, [
            'quality_scorecard' => $scorecard,
        ]);
        app(GenerationQualityScorecardService::class)->storeOnContentItem($item, $scorecard, $run);
        $this->updateGenerationAuditMeta($item, $run?->id, 'failed', [
            'failed_at' => now()->toDateTimeString(),
            'quality_scorecard_status' => (string) ($scorecard['publish_readiness_status'] ?? ''),
        ]);
        $item->save();

        app(WorkspaceNotificationService::class)->notifyTenant(
            (int) $item->tenant_id,
            'Generazione contenuto non riuscita',
            $this->contentNotificationLabel($item) . ' non e stato generato correttamente. Controlla il dettaglio tecnico e riprova.',
            [
                'level' => 'error',
                'icon' => 'ai-error',
                'action_url' => route('posts.edit', $item),
                'action_label' => 'Apri contenuto',
                'context_type' => 'content_item',
                'context_id' => (int) $item->id,
                'meta' => [
                    'ai_error' => Str::limit((string) $item->ai_error, 220, ''),
                ],
            ]
        );
    }

    // Internal helper surface temporarily exposed to pipeline step classes during the progressive refactor.
    public function generationVersionMeta(array $meta = [], array $providerMatrix = []): array
    {
        return app(GenerationVersionRegistry::class)->versionMap(
            meta: $meta,
            providerMatrix: $providerMatrix,
            jobClass: self::class
        );
    }

    public function requestedProviderMatrixSnapshot(array $meta): array
    {
        return [
            'text' => (string) data_get($meta, 'text_provider', ''),
            'grader' => (string) data_get($meta, 'grader_provider', ''),
            'image' => (string) data_get($meta, 'image_provider', ''),
            'speech' => (string) data_get($meta, 'speech_provider', ''),
            'video' => (string) data_get($meta, 'video_provider', ''),
        ];
    }

    public function requestedOutputSummary(ContentItem $item, array $meta): array
    {
        return [
            'format' => (string) $item->format,
            'platform' => (string) $item->platform,
            'video_provider' => (string) data_get($meta, 'video_provider', ''),
            'video_provider_lock' => (bool) data_get($meta, 'video_provider_lock', false),
            'image_provider' => (string) data_get($meta, 'image_provider', ''),
            'requested_video_seconds' => (int) data_get($meta, 'video_duration_seconds_requested', 0),
            'overlay_mode' => (string) data_get($meta, 'overlay_settings.mode', data_get($meta, 'overlay_meta.mode', 'auto')),
            'overlay_preset' => (string) data_get($meta, 'overlay_settings.preset', data_get($meta, 'overlay_meta.preset.key', '')),
            'asset_selection' => $this->buildAssetSelectionAuditSummary($meta, true),
        ];
    }

    public function buildRunEffectiveOutput(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $videoPath = trim((string) data_get($meta, 'video_generation.video_path', ''));

        return [
            'format' => (string) $item->format,
            'platform' => (string) $item->platform,
            'ai_status' => (string) $item->ai_status,
            'image_provider' => (string) data_get($meta, 'image_generation.provider', data_get($meta, 'image_provider', '')),
            'video_provider' => (string) data_get($meta, 'video_generation.provider', data_get($meta, 'video_provider', '')),
            'image_path' => trim((string) ($item->ai_image_path ?? '')),
            'video_path' => $videoPath,
            'target_total_seconds' => (int) data_get($meta, 'video_generation.target_total_seconds', 0),
            'segment_count' => (int) data_get($meta, 'video_generation.segment_count', 0),
            'audio_applied' => (bool) data_get($meta, 'video_generation.audio.applied', false),
            'overlay_enabled' => (string) data_get($meta, 'overlay_meta.mode', 'auto') !== 'off',
            'overlay_render_applied' => (bool) data_get($meta, 'overlay_meta.rendering.applied', false),
            'overlay_render_output_path' => (string) data_get($meta, 'overlay_meta.rendering.output_path', ''),
            'storyboard_scene_count' => (int) data_get($meta, 'storyboard_meta.scene_count', 0),
            'storyboard_hook_scene_present' => (bool) data_get($meta, 'storyboard_meta.hook_scene_present', false),
            'storyboard_cta_scene_present' => (bool) data_get($meta, 'storyboard_meta.cta_scene_present', false),
        ];
    }

    public function buildRunResultSummary(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        return [
            'ai_status' => (string) $item->ai_status,
            'ai_error' => trim((string) ($item->ai_error ?? '')),
            'caption_present' => trim((string) ($item->ai_caption ?? '')) !== '',
            'image_path_present' => trim((string) ($item->ai_image_path ?? '')) !== '',
            'video_path_present' => trim((string) data_get($meta, 'video_generation.video_path', '')) !== '',
            'visual_provider_last_used' => $this->resolveVisualProviderLastUsed($item),
            'overlay_mode' => (string) data_get($meta, 'overlay_meta.mode', ''),
            'overlay_render_applied' => (bool) data_get($meta, 'overlay_meta.rendering.applied', false),
            'overlay_readability_score' => data_get($meta, 'overlay_meta.readability.overall_score'),
            'storyboard_scene_count' => (int) data_get($meta, 'storyboard_meta.scene_count', 0),
            'storyboard_hook_scene_present' => (bool) data_get($meta, 'storyboard_meta.hook_scene_present', false),
            'storyboard_cta_scene_present' => (bool) data_get($meta, 'storyboard_meta.cta_scene_present', false),
            'asset_selection' => $this->buildAssetSelectionAuditSummary($meta, true),
        ];
    }

    public function buildAssetSelectionAuditSummary(array $meta, bool $includeRanking = false): array
    {
        $scoring = (array) data_get($meta, 'asset_scoring', []);
        if ($scoring === []) {
            return [];
        }

        $summary = [
            'version' => (string) data_get($scoring, 'version', 'asset_scoring_engine_v1'),
            'selection_area' => (string) data_get($scoring, 'selection_area', ''),
            'provider' => (string) data_get($scoring, 'provider', ''),
            'primary_asset' => data_get($scoring, 'primary_asset'),
            'supporting_assets' => array_slice((array) data_get($scoring, 'supporting_assets', []), 0, 4),
            'excluded_assets' => array_slice((array) data_get($scoring, 'excluded_assets', []), 0, 6),
            'fallback_assets' => array_slice((array) data_get($scoring, 'fallback_assets', []), 0, 4),
            'reference_paths' => array_values(array_filter(array_map('strval', (array) data_get($scoring, 'reference_paths', [])))),
            'identity_confidence' => (float) data_get($scoring, 'identity_confidence', 0.0),
            'selection_summary' => (array) data_get($scoring, 'selection_summary', []),
        ];

        if ($includeRanking) {
            $summary['asset_ranking'] = array_slice((array) data_get($scoring, 'asset_ranking', []), 0, 12);
        }

        return $summary;
    }

    public function buildVisualAttemptOutputSummary(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        if ($this->isVideoFormat($item)) {
            return [
                'kind' => 'video',
                'provider' => (string) data_get($meta, 'video_generation.provider', data_get($meta, 'video_provider', '')),
                'source' => (string) data_get($meta, 'video_generation.source', ''),
                'video_path_present' => trim((string) data_get($meta, 'video_generation.video_path', '')) !== '',
                'thumbnail_path_present' => trim((string) data_get($meta, 'video_generation.thumbnail_path', '')) !== '',
                'segment_count' => (int) data_get($meta, 'video_generation.segment_count', 0),
                'generation_attempts' => (int) data_get($meta, 'video_generation.generation_attempts', 0),
                'audio_applied' => (bool) data_get($meta, 'video_generation.audio.applied', false),
                'storyboard_scene_count' => (int) data_get($meta, 'storyboard_meta.scene_count', 0),
                'asset_selection' => $this->buildAssetSelectionAuditSummary($meta, false),
            ];
        }

        return [
            'kind' => 'image',
            'provider' => (string) data_get($meta, 'image_generation.provider', data_get($meta, 'image_provider', '')),
            'source' => (string) data_get($meta, 'image_generation.source', ''),
            'image_path_present' => trim((string) ($item->ai_image_path ?? '')) !== '',
            'fallback' => (string) data_get($meta, 'image_generation.fallback', data_get($meta, 'image_fallback', '')),
            'logo_overlay_applied' => (bool) data_get($meta, 'image_generation.logo_overlay.applied', false),
            'asset_selection' => $this->buildAssetSelectionAuditSummary($meta, false),
        ];
    }

    public function buildVisualAttemptAuditAttributes(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        if ($this->isVideoFormat($item)) {
            $provider = $this->resolveVisualProviderLastUsed($item);
            $requestSummary = (array) data_get($meta, 'video_generation.request_summary', []);
            $model = $this->normalizeVideoModelForProvider(
                $provider,
                (string) data_get($requestSummary, 'model', (string) data_get($meta, 'video_model', '')),
                [
                    'mode' => (string) data_get($requestSummary, 'request_mode', ''),
                ]
            );
            $requestedSeconds = (int) data_get($meta, 'video_duration_seconds_requested', 0);
            $normalizedSeconds = (int) data_get(
                $requestSummary,
                'seconds',
                data_get($requestSummary, 'duration', 0)
            );
            if ($normalizedSeconds <= 0 && $requestedSeconds > 0) {
                $normalizedSeconds = $this->normalizeVideoSecondsForProvider($provider, $requestedSeconds, $model);
            }

            return [
                'provider_effective' => $provider,
                'model_effective' => $model !== '' ? $model : null,
                'requested_duration_seconds' => $requestedSeconds > 0 ? $requestedSeconds : null,
                'normalized_duration_seconds' => $normalizedSeconds > 0 ? $normalizedSeconds : null,
                'external_request_id' => trim((string) data_get($meta, 'video_generation.video_id', '')) ?: null,
                'output_references' => $this->buildVisualAttemptOutputReferences($item),
            ];
        }

        $provider = $this->resolveVisualProviderLastUsed($item);

        return [
            'provider_effective' => $provider,
            'model_effective' => $this->capabilityRegistry()->defaultModel($provider, 'image') ?: null,
            'output_references' => $this->buildVisualAttemptOutputReferences($item),
        ];
    }

    public function buildVisualAttemptOutputReferences(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        if ($this->isVideoFormat($item)) {
            return [
                'video_path' => trim((string) data_get($meta, 'video_generation.video_path', '')),
                'thumbnail_path' => trim((string) data_get($meta, 'video_generation.thumbnail_path', '')),
                'reference_paths' => array_values(array_filter(
                    array_map('strval', (array) data_get($meta, 'video_generation.reference_paths', [])),
                    fn ($path) => $path !== ''
                )),
                'asset_selection_ranking' => array_slice((array) data_get($meta, 'asset_scoring.asset_ranking', []), 0, 12),
            ];
        }

        return [
            'image_path' => trim((string) ($item->ai_image_path ?? '')),
            'brand_source_paths' => array_values(array_filter(
                array_map('strval', (array) data_get($meta, 'image_generation.brand_source_paths', [])),
                fn ($path) => $path !== ''
            )),
            'asset_selection_ranking' => array_slice((array) data_get($meta, 'asset_scoring.asset_ranking', []), 0, 12),
        ];
    }

    public function resolveVisualProviderLastUsed(ContentItem $item): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        if ($this->isVideoFormat($item)) {
            return (string) data_get($meta, 'video_generation.provider', data_get($meta, 'video_provider_last_used', data_get($meta, 'video_provider', '')));
        }

        return (string) data_get($meta, 'image_generation.provider', data_get($meta, 'image_provider', ''));
    }

    public function updateGenerationAuditMeta(ContentItem $item, ?int $runId, string $status, array $extra = []): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $versionMap = $extra['version_map'] ?? null;

        if (!is_array($versionMap) && $runId) {
            $versionMap = \App\Models\GenerationRun::query()
                ->whereKey($runId)
                ->value('version_meta');
        }

        $meta['generation_audit'] = array_merge(
            (array) data_get($meta, 'generation_audit', []),
            [
                'latest_run_id' => $runId,
                'latest_run_key' => $this->runKey,
                'latest_status' => $status,
                'tracked_at' => now()->toDateTimeString(),
            ],
            is_array($versionMap) && !empty($versionMap)
                ? ['version_map' => $versionMap]
                : [],
            $extra
        );
        $item->ai_meta = $meta;
    }

    public function touchGenerationRuntime(ContentItem $item, string $stage, string $label, int $progress, array $extra = []): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $runtime = (array) data_get($meta, 'generation_runtime', []);
        $runtime = array_merge($runtime, [
            'run_key' => $this->runKey,
            'stage' => $stage,
            'stage_label' => $label,
            'progress' => max(0, min(100, $progress)),
            'heartbeat_at' => now()->toDateTimeString(),
            'status' => (string) ($item->ai_status ?: 'queued'),
        ], $extra);

        if (trim((string) ($runtime['started_at'] ?? '')) === '') {
            $runtime['started_at'] = now()->toDateTimeString();
        }

        $meta['generation_runtime'] = $runtime;
        $item->ai_meta = $meta;
        $item->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markGenerationRuntimeFinished(ContentItem $item, string $status, string $label, array $extra = []): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $runtime = (array) data_get($meta, 'generation_runtime', []);
        $runtime = array_merge($runtime, [
            'run_key' => $this->runKey,
            'stage' => strtolower(trim($status)) === 'done' ? 'completed' : 'failed',
            'stage_label' => $label,
            'progress' => 100,
            'heartbeat_at' => now()->toDateTimeString(),
            'finished_at' => now()->toDateTimeString(),
            'status' => $status,
        ], $extra);

        if (trim((string) ($runtime['started_at'] ?? '')) === '') {
            $runtime['started_at'] = now()->toDateTimeString();
        }

        $meta['generation_runtime'] = $runtime;
        $item->ai_meta = $meta;
    }

    private function processingLockKey(): string
    {
        return 'generation:content-item-processing:' . max(1, $this->contentItemId);
    }

    public function isDemoMode(): bool
    {
        return (bool) config('app.demo_mode', false);
    }

    public function applyDemoPreset(ContentItem $item, array $tenantProfile, array $itemBrain, array $meta): void
    {
        $preset = $this->buildDemoPreset($item, $tenantProfile, $itemBrain);

        $item->title = $item->title ?: ($preset['title'] ?? 'Contenuto demo');
        $item->ai_caption = $preset['caption'];
        $item->ai_hashtags = $preset['hashtags'];
        $item->ai_cta = $preset['cta'];
        $item->ai_image_prompt = $preset['image_prompt'];
        $item->ai_error = null;

        $demoImagePath = $this->chooseDemoImagePath($item);
        if (!$demoImagePath) {
            $demoImagePath = $this->createLocalImagePlaceholder($item, $tenantProfile);
        }
        if ($demoImagePath) {
            $item->ai_image_path = $demoImagePath;
        }

        $demoMeta = [
            'demo_mode' => true,
            'demo_source' => 'hostup_preset_v1',
            'generated_at' => now()->toDateTimeString(),
            'image_source' => $demoImagePath ? 'brand_or_placeholder' : 'none',
        ];

        $item->ai_meta = array_merge($meta, ['demo' => $demoMeta]);

        $assets = is_array($item->assets) ? $item->assets : [];
        if ($demoImagePath) {
            $assets[] = ['type' => 'demo_image', 'path' => $demoImagePath];
            $item->assets = $this->uniqueAssets($assets);
        }

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $this->markFeedbackRequestAsApplied($item);
        $item->save();
    }

    public function markFeedbackRequestAsApplied(ContentItem $item): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $active = data_get($meta, 'feedback_loop.active_request');

        if (!is_array($active) || empty($active)) {
            return;
        }

        $active['applied_at'] = now()->toDateTimeString();
        $meta['feedback_loop']['last_applied'] = $active;
        $meta['feedback_loop']['active_request'] = null;
        $item->ai_meta = $meta;
    }

    public function notifyAiSuccess(ContentItem $item, WorkspaceNotificationService $workspaceNotifications): void
    {
        $workspaceNotifications->notifyTenant(
            (int) $item->tenant_id,
            'Contenuto pronto',
            $this->contentNotificationLabel($item) . ' e pronto da rivedere o approvare.',
            [
                'level' => 'success',
                'icon' => 'ai-done',
                'action_url' => route('posts.edit', $item),
                'action_label' => 'Apri contenuto',
                'context_type' => 'content_item',
                'context_id' => (int) $item->id,
                'meta' => [
                    'format' => (string) $item->format,
                    'platform' => (string) $item->platform,
                ],
            ]
        );
    }

    public function notifyAiFailure(
        ContentItem $item,
        WorkspaceNotificationService $workspaceNotifications,
        string $reason
    ): void {
        $workspaceNotifications->notifyTenant(
            (int) $item->tenant_id,
            'Generazione contenuto non riuscita',
            $this->contentNotificationLabel($item) . ' ha richiesto un intervento. Puoi rigenerarlo o correggere il prompt.',
            [
                'level' => 'error',
                'icon' => 'ai-error',
                'action_url' => route('posts.edit', $item),
                'action_label' => 'Apri contenuto',
                'context_type' => 'content_item',
                'context_id' => (int) $item->id,
                'meta' => [
                    'ai_error' => Str::limit($reason, 220, ''),
                ],
            ]
        );
    }

    public function contentNotificationLabel(ContentItem $item): string
    {
        $title = trim((string) ($item->title ?: $item->content_angle ?: 'Contenuto'));

        return '"' . Str::limit($title, 70, '') . '"';
    }

    public function buildDemoPreset(ContentItem $item, array $tenantProfile, array $itemBrain): array
    {
        $brand = trim((string) data_get($tenantProfile, 'business_name', 'Hostup'));
        $angle = trim((string) data_get($itemBrain, 'angle', $item->content_angle ?: 'Automazione affitti brevi'));
        $ctaDefault = trim((string) data_get($itemBrain, 'cta', 'Scrivici per una demo gratuita.'));
        $position = $this->positionInPlan($item);

        $presets = [
            [
                'title' => 'Prezzi dinamici senza stress',
                'caption' => "Uno degli errori piÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ comuni negli affitti brevi ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨ aggiornare i prezzi solo a mano. {$brand} automatizza tariffe e disponibilitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â  in base alla domanda reale, eventi locali e storico prenotazioni. Risultato: piÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ margine e meno camere ferme.",
                'hashtags' => ['#Hostup', '#AffittiBrevi', '#RevenueManagement', '#PropertyManagement', '#Automazione'],
                'cta' => "Vuoi vedere il flusso completo in azione? {$ctaDefault}",
                'image_prompt' => "Dashboard moderna di revenue management per affitti brevi, stile pulito tech, palette brand, scena realistica senza testo.",
            ],
            [
                'title' => 'Canali OTA allineati in tempo reale',
                'caption' => "Sincronizzare manualmente Booking, Airbnb e sito diretto crea overbooking e perdita di tempo. Con {$brand} il calendario resta coerente su tutti i canali: disponibilitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â , restrizioni e regole vengono aggiornate automaticamente.",
                'hashtags' => ['#Hostup', '#ChannelManager', '#AirbnbHost', '#BookingCom', '#ShortTermRental'],
                'cta' => "Se vuoi, ti mostriamo in 10 minuti come configurarlo sul tuo portfolio.",
                'image_prompt' => "Interfaccia channel manager multi-canale con card OTA, look future-tech, senza watermark e senza testo.",
            ],
            [
                'title' => 'Meno operativitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â , piÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ controllo',
                'caption' => "La gestione efficace non ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨ fare tutto a mano, ma avere regole chiare e automazioni affidabili. {$brand} riduce attivitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â  ripetitive e ti lascia tempo per decisioni strategiche: occupazione, pricing e qualitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â  del servizio.",
                'hashtags' => ['#Hostup', '#HospitalityTech', '#AffittiBreviItalia', '#Automation', '#SmartOperations'],
                'cta' => "Scrivici e prepariamo un setup pilota sui tuoi annunci.",
                'image_prompt' => "Team operativo hospitality che monitora KPI su schermo, stile professionale, luci soft, no testo sovraimpresso.",
            ],
            [
                'title' => 'Dati utili, non solo numeri',
                'caption' => "Guardare solo il tasso di occupazione non basta. Con {$brand} hai indicatori chiave leggibili subito: ADR, RevPAR, conversione e trend per canale. In questo modo ottimizzi ogni settimana con decisioni basate sui dati.",
                'hashtags' => ['#Hostup', '#DataDriven', '#ADR', '#RevPAR', '#RentalBusiness'],
                'cta' => "Ti facciamo vedere quali KPI monitorare da subito nel tuo caso.",
                'image_prompt' => "Cruscotto analytics hospitality con grafici moderni, visuale nitida, stile premium tech, senza testo.",
            ],
            [
                'title' => 'Template operativi pronti',
                'caption' => "Standardizzare i processi fa la differenza quando il numero di annunci cresce. {$brand} applica template e regole ripetibili per velocizzare operazioni quotidiane e mantenere qualitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â  costante.",
                'hashtags' => ['#Hostup', '#Processi', '#PropertyOps', '#ScalabilitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ', '#DigitalHospitality'],
                'cta' => "Vuoi una checklist pronta per partire? Te la condividiamo.",
                'image_prompt' => "Vista workflow operativo per property management, cards ordinate e look minimal futuristico.",
            ],
            [
                'title' => 'Setup rapido per team piccoli',
                'caption' => "Anche con un team ridotto puoi gestire in modo professionale: meno tool scollegati, piÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ controllo centralizzato. {$brand} organizza attivitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â , prioritÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â  e pubblicazione contenuti in un unico flusso chiaro.",
                'hashtags' => ['#Hostup', '#TeamProduttivo', '#Workflow', '#SmartTools', '#BusinessGrowth'],
                'cta' => "Prenota una prova: impostiamo insieme il primo piano operativo.",
                'image_prompt' => "Scrivania moderna con laptop e pannello operativo, mood tech pulito, nessun testo visibile.",
            ],
        ];

        $preset = $presets[$position % count($presets)];
        $preset['caption'] = trim($preset['caption'] . ' ' . ($angle !== '' ? "Focus del post: {$angle}." : ''));
        $preset['cta'] = trim($preset['cta']);

        return $preset;
    }

    public function chooseDemoImagePath(ContentItem $item): ?string
    {
        $paths = BrandAsset::query()
            ->where('tenant_id', $item->tenant_id)
            ->whereNull('content_plan_id')
            ->where('kind', 'image')
            ->orderBy('id')
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();

        if (empty($paths)) {
            return null;
        }

        $pos = $this->positionInPlan($item);
        return $paths[$pos % count($paths)] ?? null;
    }

    public function isQuotaOrRateLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'status code 429')
            || str_contains($message, 'exceeded your current quota')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'insufficient_quota');
    }

    public function isTransientNetworkError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 6')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'curl error 7')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'temporary failure in name resolution');
    }

    public function fallbackText(ContentItem $item, array $tenantProfile, array $itemBrain): array
    {
        $business = trim((string) data_get($tenantProfile, 'business_name', 'Brand'));
        $angle = trim((string) data_get($itemBrain, 'angle', $item->title ?: 'Contenuto'));
        $objective = trim((string) data_get($itemBrain, 'objective', 'Awareness'));
        $cta = trim((string) data_get($itemBrain, 'cta', 'Scrivici per maggiori dettagli.'));
        $industry = trim((string) data_get($tenantProfile, 'industry', 'business'));

        $caption = "{$business}: {$angle}. "
            . "Obiettivo: {$objective}. "
            . "Contenuto generato in fallback temporaneo per limite quota AI.";

        $hashtags = [
            '#marketingdigitale',
            '#contenuti',
            '#' . Str::slug($industry),
            '#strategiabrand',
        ];

        $imagePrompt = "Visual social quadrato per {$business}. "
            . "Tema: {$angle}. Stile pulito e professionale, senza testo sovraimpresso. "
            . "Deve sembrare un post social vero, pensato per il feed e non una foto corporate generica. "
            . "Evita loghi finti, watermark e testo brand inventato. Tutto in italiano.";

        return [
            'caption' => $caption,
            'hashtags' => $hashtags,
            'cta' => $cta,
            'image_prompt' => $imagePrompt,
        ];
    }

    public function isImageBillingLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'billing hard limit')
            || str_contains($message, 'billing_limit_user_error')
            || str_contains($message, 'insufficient_quota');
    }

    public function createLocalImagePlaceholder(ContentItem $item, array $tenantProfile): ?string
    {
        try {
            $brand = trim((string) data_get($tenantProfile, 'business_name', 'Brand'));
            $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $brand), 0, 2) ?: 'BR');
            $palette = (string) data_get($tenantProfile, 'brand_palette', '#0f172a,#2563eb');
            $parts = array_values(array_filter(array_map('trim', explode(',', $palette))));
            $c1 = $parts[0] ?? '#0f172a';
            $c2 = $parts[1] ?? '#2563eb';

            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$c1}"/>
      <stop offset="100%" stop-color="{$c2}"/>
    </linearGradient>
  </defs>
  <rect width="1024" height="1024" fill="url(#g)"/>
  <circle cx="512" cy="512" r="260" fill="rgba(255,255,255,0.16)"/>
  <text x="50%" y="53%" dominant-baseline="middle" text-anchor="middle"
        font-family="Arial, Helvetica, sans-serif" font-size="220" font-weight="700" fill="#ffffff">{$initials}</text>
</svg>
SVG;

            $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.svg';
            Storage::disk('public')->put($filename, $svg);

            return $filename;
        } catch (Throwable) {
            return null;
        }
    }

    public function isVideoFormat(ContentItem $item): bool
    {
        $format = strtolower(trim((string) ($item->format ?? '')));
        return in_array($format, ['reel', 'story', 'video'], true);
    }

    public function shouldUseImageReferenceForVideo(string $briefNormalized): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        $explicit = [
            'parti dalla foto',
            'parti dall immagine',
            'usa questa foto',
            'usa questa immagine',
            'usa esattamente',
            'stessa foto',
            'stessa immagine',
            'come base la foto',
            'come base l immagine',
            'from this photo',
            'from this image',
            'use this image as base',
            'same image',
        ];

        foreach ($explicit as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $logoRuntime
     * @param  array<string, mixed>  $brandDecision
     * @return array<string, mixed>
     */
    public function generateVideoAsset(
        OpenAiService $openAi,
        NanoBananaService $nanoBanana,
        RunwayService $runway,
        KlingService $kling,
        GoogleVeoService $googleVeo,
        ContentItem $item,
        string $prompt,
        ?string $selectedBrandImageAbs,
        array $selectedBrandImageAbsList,
        ?string $selectedBrandImagePath,
        array $selectedBrandImagePaths,
        array $logoRuntime,
        array $activeFeedbackRequest,
        array $brandDecision
    ): array {
        $logoRequested = (bool) ($logoRuntime['requested'] ?? false);
        $logoMode = (string) ($logoRuntime['mode'] ?? 'scene');
        $logoAbs = isset($logoRuntime['abs']) && is_string($logoRuntime['abs']) ? $logoRuntime['abs'] : null;
        $logoPath = isset($logoRuntime['path']) && is_string($logoRuntime['path']) ? $logoRuntime['path'] : null;

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $briefRaw = trim((string) data_get($meta, 'manual_brief', ''));
        $briefNorm = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        $assetVariables = (array) data_get($meta, 'asset_variables', []);
        $videoProvider = $this->resolveVideoProvider($meta);
        $allowSecondaryVideoFallback = $this->canAttemptSecondaryVideoFallback($meta, $videoProvider);
        $explicitReferencePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'image_references.selected_paths', [])
        )));
        $variableReferencePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'asset_variables.resolved_asset_paths', [])
        )));
        if (empty($variableReferencePaths)) {
            $variableReferencePaths = collect((array) data_get($meta, 'asset_variables.resolved', []))
                ->flatMap(fn ($row) => is_array($row) ? (array) ($row['asset_paths'] ?? []) : [])
                ->map(fn ($path) => (string) $path)
                ->filter(fn ($path) => $path !== '')
                ->values()
                ->all();
        }
        if (!empty($variableReferencePaths)) {
            $explicitReferencePaths = array_values(array_unique(array_merge($explicitReferencePaths, $variableReferencePaths)));
        }
        $hasExplicitReferences = !empty($explicitReferencePaths);
        $useImageReference = $hasExplicitReferences || $this->shouldUseImageReferenceForVideo($briefNorm);

        $imageReferenceAbs = $useImageReference ? $selectedBrandImageAbs : null;
        $imageReferencePath = $useImageReference ? $selectedBrandImagePath : null;

        $imageReferenceAbsPool = $useImageReference ? array_values(array_filter($selectedBrandImageAbsList, fn ($v) => is_string($v) && $v !== '')) : [];
        $imageReferencePathPool = $useImageReference ? array_values(array_filter($selectedBrandImagePaths, fn ($v) => is_string($v) && $v !== '')) : [];
        if (empty($imageReferenceAbsPool) && is_string($imageReferenceAbs) && $imageReferenceAbs !== '') {
            $imageReferenceAbsPool = [$imageReferenceAbs];
        }
        if (empty($imageReferencePathPool) && is_string($imageReferencePath) && $imageReferencePath !== '') {
            $imageReferencePathPool = [$imageReferencePath];
        }
        $videoControlContext = $this->videoSubjectContextText($meta, $briefRaw !== '' ? $briefRaw : $prompt, (string) data_get($meta, 'video_prompt', ''));
        [$imageReferenceAbsPool, $imageReferencePathPool] = $this->prioritizeVideoReferencePoolsForPersonVariable(
            $imageReferenceAbsPool,
            $imageReferencePathPool,
            $assetVariables,
            $meta,
            $videoControlContext
        );
        $locationSequenceMode = $hasExplicitReferences
            && count($imageReferenceAbsPool) >= 2
            && $this->hasProtectedLocationEnvelope($assetVariables, $imageReferencePathPool);
        $mustEnforceExplicitReferences = $hasExplicitReferences && !empty($imageReferenceAbsPool) && !$locationSequenceMode;
        $mustValidateReferenceMatch = $this->shouldValidateVideoReferenceMatch(
            hasExplicitReferences: $hasExplicitReferences,
            locationSequenceMode: $locationSequenceMode,
            assetVariables: $assetVariables,
            meta: $meta,
            referenceAbsPool: $imageReferenceAbsPool
        );
        $dualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $videoControlContext, $assetVariables);
        $klingIdentityBoardMode = $videoProvider === 'kling'
            && !$dualSubjectLock
            && $this->shouldUsePersonIdentityReferenceBoard($assetVariables, $imageReferencePathPool);
        $runwayPrimaryAnchorMode = $videoProvider === 'runway'
            && !$dualSubjectLock
            && Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel'
            && count($imageReferenceAbsPool) >= 2;
        $openAiPrimaryPersonReferenceMode = $this->shouldUseOpenAiPrimaryPersonReference(
            videoProvider: $videoProvider,
            dualSubjectLock: $dualSubjectLock,
            assetVariables: $assetVariables,
            referencePaths: $imageReferencePathPool
        );
        $validationReferenceAbsPool = array_values(array_slice($imageReferenceAbsPool, 0, 4));
        $generationReferenceAbsPool = $imageReferenceAbsPool;
        $compositionReference = null;
        $compositionMeta = null;

        if ($locationSequenceMode) {
            $generationReferenceAbsPool = [(string) ($imageReferenceAbsPool[0] ?? '')];
            $compositionMeta = [
                'used' => false,
                'mode' => 'sequential_real_locations',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($klingIdentityBoardMode) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'kling_person_identity_reference_board',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($runwayPrimaryAnchorMode) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'runway_primary_anchor_reference',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif ($openAiPrimaryPersonReferenceMode) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'openai_primary_person_reference',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif (!$dualSubjectLock && $this->shouldUsePersonIdentityReferenceBoard($assetVariables, $imageReferencePathPool)) {
            $compositionMeta = [
                'used' => false,
                'mode' => 'person_identity_reference_board',
                'reference_count' => count($imageReferenceAbsPool),
            ];
        } elseif (
            $mustEnforceExplicitReferences
            && count($imageReferenceAbsPool) >= 2
            && $this->shouldAttemptLockedVideoSceneReference($videoProvider, $dualSubjectLock)
        ) {
            $compositionReference = $this->buildLockedVideoSceneReference(
                openAi: $openAi,
                nanoBanana: $nanoBanana,
                brief: $briefRaw !== '' ? $briefRaw : $prompt,
                prompt: $prompt,
                referenceAbsPaths: $imageReferenceAbsPool,
                assetVariables: $assetVariables
            );

            if ($this->shouldUseLockedVideoSceneReference($compositionReference, $videoProvider, $dualSubjectLock)) {
                $generationReferenceAbsPool = [(string) $compositionReference['abs']];
                $compositionMeta = [
                    'used' => true,
                    'attempts' => (int) ($compositionReference['attempts'] ?? 1),
                    'all_present' => (bool) ($compositionReference['all_present'] ?? false),
                    'validation' => $compositionReference['validation'] ?? null,
                ];
            } elseif (is_array($compositionReference) && !empty($compositionReference['abs'])) {
                $compositionMeta = [
                    'used' => false,
                    'mode' => 'locked_scene_reference_rejected',
                    'attempts' => (int) ($compositionReference['attempts'] ?? 1),
                    'all_present' => (bool) ($compositionReference['all_present'] ?? false),
                    'validation' => $compositionReference['validation'] ?? null,
                ];
            }
        }

        $referenceAbs = null;
        $referencePath = null;
        $referencePaths = [];
        $referenceReason = 'no_reference';
        $tempRefPaths = [];
        $preparedRefPath = null;
        if (is_array($compositionReference) && !empty($compositionReference['abs'])) {
            $tempRefPaths[] = (string) $compositionReference['abs'];
        }

        if (!empty($generationReferenceAbsPool)) {
            if ($klingIdentityBoardMode) {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = 'kling_person_identity_reference_board';
            } elseif ($runwayPrimaryAnchorMode) {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = 'runway_primary_anchor_reference';
            } elseif ($openAiPrimaryPersonReferenceMode) {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = 'openai_primary_person_reference';
            } elseif (count($generationReferenceAbsPool) > 1) {
                $collage = $this->buildVideoReferenceCollage(array_slice($generationReferenceAbsPool, 0, 4));
                if (is_string($collage) && $collage !== '') {
                    $referenceAbs = $collage;
                    $referencePath = $imageReferencePathPool[0] ?? null;
                    $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                    $referenceReason = 'brand_multi_image_collage_reference';
                    $tempRefPaths[] = $collage;
                } else {
                    $referenceAbs = $generationReferenceAbsPool[0];
                    $referencePath = $imageReferencePathPool[0] ?? null;
                    $referencePaths = [$referencePath];
                    $referenceReason = 'brand_image_reference_collage_fallback_first';
                }
            } else {
                $referenceAbs = $generationReferenceAbsPool[0];
                $referencePath = $imageReferencePathPool[0] ?? null;
                $referencePaths = array_values(array_slice($imageReferencePathPool, 0, 4));
                $referenceReason = (is_array($compositionReference) && !empty($compositionReference['abs']))
                    ? 'brand_locked_scene_reference'
                    : 'brand_image_reference';
            }
        }

        if ($openAiPrimaryPersonReferenceMode && $logoRequested && $logoAbs) {
            $referenceReason .= '_logo_prompt_only';
        } elseif ($videoProvider !== 'kling' && $logoRequested && $logoAbs) {
            if ($referenceAbs) {
                $composed = $this->buildVideoReferenceImage($referenceAbs, $logoAbs, $logoMode);
                if ($composed) {
                    $referenceAbs = $composed;
                    $referenceReason = count(array_filter($referencePaths, fn ($v) => is_string($v) && $v !== '')) > 1
                        ? 'brand_multi_plus_logo_composed_reference'
                        : 'brand_plus_logo_composed_reference';
                    $tempRefPaths[] = $composed;
                } else {
                    $referenceAbs = $logoAbs;
                    $referencePath = $logoPath;
                    $referencePaths = $logoPath ? [$logoPath] : [];
                    $referenceReason = 'logo_only_reference_fallback';
                }
            } else {
                $referenceAbs = $logoAbs;
                $referencePath = $logoPath;
                $referencePaths = $logoPath ? [$logoPath] : [];
                $referenceReason = 'logo_only_reference';
            }
        } elseif ($videoProvider === 'kling' && $logoRequested && $logoAbs) {
            $referenceReason .= '_logo_prompt_only';
        }

        $videoPrompt = $this->buildStrategicVideoPrompt(
            item: $item,
            meta: $meta,
            briefRaw: $briefRaw !== '' ? $briefRaw : $prompt,
            selectedBrandImageAbs: $selectedBrandImageAbs,
            generationReferenceAbsPool: $generationReferenceAbsPool,
            referencePaths: $referencePaths,
            assetVariables: $assetVariables,
            activeFeedbackRequest: $activeFeedbackRequest,
            logoRequested: $logoRequested,
            logoAbs: $logoAbs,
            logoMode: $logoMode,
            locationSequenceMode: $locationSequenceMode,
            mustEnforceExplicitReferences: $mustEnforceExplicitReferences
        );

        $videoOptions = [
            'model' => match ($videoProvider) {
                'runway' => $this->resolveRunwayVideoModel($item, $meta, $assetVariables, $imageReferencePathPool),
                'google_veo' => $this->resolveGoogleVeoVideoModel($meta),
                'kling' => (string) (config('kling.model') ?: ''),
                default => (string) (config('openai.video_model') ?: 'sora-2'),
            },
            'seconds' => $this->targetVideoSecondsForFormat($item),
            'size' => $this->targetVideoSizeForFormat($item),
        ];
        $runwayExecutionPrompt = $videoProvider === 'runway'
            ? $this->buildRunwayReelExecutionPrompt($videoPrompt, $item, $meta, $assetVariables)
            : $videoPrompt;
        $klingExecutionPrompt = $videoProvider === 'kling'
            ? $this->buildKlingExecutionPrompt($videoPrompt, $item, $meta, $assetVariables, $activeFeedbackRequest)
            : $videoPrompt;
        $googleVeoExecutionPrompt = $videoProvider === 'google_veo'
            ? $this->buildGoogleVeoExecutionPrompt($videoPrompt, $item, $meta, $assetVariables)
            : $videoPrompt;
        $openAiExecutionPrompt = $this->prepareOpenAiVideoPromptForExecution(
            $videoPrompt,
            $briefRaw !== '' ? $briefRaw : $prompt,
            $referencePaths,
            $assetVariables
        );

        if ($videoProvider !== 'kling' && is_string($referenceAbs) && $referenceAbs !== '') {
            $prepared = $this->prepareVideoReferenceForSize($referenceAbs, (string) $videoOptions['size']);
            if ($prepared) {
                $preparedRefPath = $prepared;
                $referenceAbs = $preparedRefPath;
                $referenceReason .= '_normalized_to_size';
            }
        }

        $targetTotalSeconds = $this->targetTotalVideoSecondsForItem($item, $videoProvider);
        $extendedVideoFallback = null;
        $finalizeVideoResult = function (array $result) use (&$extendedVideoFallback): array {
            return $this->applyExtendedVideoSingleClipFallback($result, $extendedVideoFallback);
        };

        if ($this->shouldGenerateExtendedVideo($item, $videoProvider, $targetTotalSeconds, (string) ($videoOptions['model'] ?? ''))) {
            $ffmpeg = $this->resolveFfmpegBinary();
            if ($this->canRunBinary($ffmpeg)) {
                return $this->generateExtendedVideoAsset(
                    openAi: $openAi,
                    runway: $runway,
                    kling: $kling,
                    googleVeo: $googleVeo,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $prompt,
                    videoProvider: $videoProvider,
                    runwayExecutionPrompt: $runwayExecutionPrompt,
                    klingExecutionPrompt: $klingExecutionPrompt,
                    googleVeoExecutionPrompt: $googleVeoExecutionPrompt,
                    openAiExecutionPrompt: $openAiExecutionPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $referenceReason,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool,
                    validationReferenceAbsPool: $validationReferenceAbsPool,
                    mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $videoOptions,
                    assetVariables: $assetVariables,
                    activeFeedbackRequest: $activeFeedbackRequest,
                    locationSequenceMode: $locationSequenceMode,
                    targetTotalSeconds: $targetTotalSeconds
                );
            }

            $extendedVideoFallback = $this->buildExtendedVideoSingleClipFallback(
                provider: $videoProvider,
                targetTotalSeconds: $targetTotalSeconds,
                videoOptions: $videoOptions,
                ffmpegBinary: $ffmpeg
            );
            $videoOptions['seconds'] = (int) ($extendedVideoFallback['delivered_seconds'] ?? $videoOptions['seconds']);
            $referenceReason .= '_single_clip_fallback_no_ffmpeg';

            Log::warning('GenerateAiForContentItem extended video downgraded to single clip', [
                'content_item_id' => $item->id,
                'provider' => $videoProvider,
                'requested_total_seconds' => $targetTotalSeconds,
                'delivered_seconds' => $videoOptions['seconds'],
                'ffmpeg_binary' => $ffmpeg,
            ]);
        }

        if ($videoProvider === 'google_veo') {
            try {
                return $finalizeVideoResult($this->generateVideoWithGoogleVeo(
                    googleVeo: $googleVeo,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $prompt,
                    videoPrompt: $googleVeoExecutionPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $referenceReason,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $videoOptions,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool
                ));
            } catch (Throwable $googleVeoError) {
                if (!$allowSecondaryVideoFallback || !$this->shouldFallbackFromGoogleVeoToSecondaryProvider($googleVeoError)) {
                    throw $googleVeoError;
                }

                $fallbackProviders = $this->secondaryVideoProvidersForGoogleVeoFailure();
                $fallbackFailures = [];

                foreach ($fallbackProviders as $fallbackProvider) {
                    try {
                        if ($fallbackProvider === 'kling') {
                            $result = $this->generateVideoWithKling(
                                kling: $kling,
                                item: $item,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $klingExecutionPrompt,
                                referenceAbs: is_string($referenceAbs) ? $referenceAbs : null,
                                referencePath: is_string($referencePath) ? $referencePath : null,
                                referenceAbsPool: $generationReferenceAbsPool,
                                referencePaths: $imageReferencePathPool,
                                referenceReason: $referenceReason . '_kling_fallback_after_google_veo_failure',
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions,
                                assetVariables: $assetVariables,
                                activeFeedbackRequest: $activeFeedbackRequest,
                                locationSequenceMode: $locationSequenceMode
                            );
                        } else {
                            $result = $this->generateVideoWithOpenAi(
                                openAi: $openAi,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $this->buildOpenAiVideoFallbackPrompt($openAiExecutionPrompt, $briefRaw, $referencePaths),
                                referenceAbs: $referenceAbs,
                                referencePath: $referencePath,
                                referencePaths: $referencePaths,
                                referenceReason: $referenceReason . '_openai_fallback_after_google_veo_failure',
                                generationReferenceAbsPool: $generationReferenceAbsPool,
                                imageReferencePathPool: $imageReferencePathPool,
                                validationReferenceAbsPool: $validationReferenceAbsPool,
                                mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions,
                                assetVariables: $assetVariables,
                                providerFallback: [
                                    'from' => 'google_veo',
                                    'to' => 'openai',
                                    'reason' => Str::limit($googleVeoError->getMessage(), 220, ''),
                                    'at' => now()->toDateTimeString(),
                                ]
                            );
                        }

                        $result['provider_fallback'] = [
                            'from' => 'google_veo',
                            'to' => $fallbackProvider,
                            'reason' => Str::limit($googleVeoError->getMessage(), 220, ''),
                            'at' => now()->toDateTimeString(),
                            'nested' => $result['provider_fallback'] ?? null,
                        ];

                        return $finalizeVideoResult($result);
                    } catch (Throwable $fallbackError) {
                        $fallbackFailures[] = $fallbackProvider . ': ' . Str::limit($fallbackError->getMessage(), 220, '');

                        Log::warning('GenerateAiForContentItem video fallback failed', [
                            'content_item_id' => $item->id,
                            'from_provider' => 'google_veo',
                            'to_provider' => $fallbackProvider,
                            'error' => $fallbackError->getMessage(),
                        ]);
                    }
                }

                if (!empty($fallbackFailures)) {
                    throw new \RuntimeException(
                        $googleVeoError->getMessage() . ' | VIDEO_PROVIDER_FALLBACKS_FAILED=' . implode(' || ', $fallbackFailures),
                        0,
                        $googleVeoError
                    );
                }

                throw $googleVeoError;
            }
        }

        if ($videoProvider === 'kling') {
            try {
                return $finalizeVideoResult($this->generateVideoWithKling(
                    kling: $kling,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $prompt,
                    videoPrompt: $klingExecutionPrompt,
                    referenceAbs: is_string($referenceAbs) ? $referenceAbs : null,
                    referencePath: is_string($referencePath) ? $referencePath : null,
                    referenceAbsPool: $generationReferenceAbsPool,
                    referencePaths: $imageReferencePathPool,
                    referenceReason: $referenceReason,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $videoOptions,
                    assetVariables: $assetVariables,
                    activeFeedbackRequest: $activeFeedbackRequest,
                    locationSequenceMode: $locationSequenceMode
                ));
            } catch (Throwable $klingError) {
                if (!$allowSecondaryVideoFallback || !$this->shouldFallbackFromKlingToSecondaryProvider($klingError)) {
                    throw $klingError;
                }

                $fallbackProviders = $this->secondaryVideoProvidersForKlingFailure();
                $fallbackFailures = [];

                foreach ($fallbackProviders as $fallbackProvider) {
                    try {
                        if ($fallbackProvider === 'runway') {
                            $result = $this->generateVideoWithRunway(
                                runway: $runway,
                                openAi: $openAi,
                                item: $item,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $runwayExecutionPrompt,
                                referenceAbs: $referenceAbs,
                                referencePath: $referencePath,
                                referencePaths: $referencePaths,
                                referenceReason: $referenceReason . '_runway_fallback_after_kling_failure',
                                generationReferenceAbsPool: $generationReferenceAbsPool,
                                imageReferencePathPool: $imageReferencePathPool,
                                validationReferenceAbsPool: $validationReferenceAbsPool,
                                mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions
                            );
                        } elseif ($fallbackProvider === 'google_veo') {
                            $result = $this->generateVideoWithGoogleVeo(
                                googleVeo: $googleVeo,
                                item: $item,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $this->buildGoogleVeoExecutionPrompt($videoPrompt, $item, $meta, $assetVariables),
                                referenceAbs: is_string($referenceAbs) ? $referenceAbs : null,
                                referencePath: is_string($referencePath) ? $referencePath : null,
                                referencePaths: $referencePaths,
                                referenceReason: $referenceReason . '_google_veo_fallback_after_kling_failure',
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions,
                                generationReferenceAbsPool: $generationReferenceAbsPool,
                                imageReferencePathPool: $imageReferencePathPool
                            );
                        } else {
                            $result = $this->generateVideoWithOpenAi(
                                openAi: $openAi,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $this->buildOpenAiVideoFallbackPrompt($openAiExecutionPrompt, $briefRaw, $referencePaths),
                                referenceAbs: $referenceAbs,
                                referencePath: $referencePath,
                                referencePaths: $referencePaths,
                                referenceReason: $referenceReason . '_openai_fallback_after_kling_failure',
                                generationReferenceAbsPool: $generationReferenceAbsPool,
                                imageReferencePathPool: $imageReferencePathPool,
                                validationReferenceAbsPool: $validationReferenceAbsPool,
                                mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions,
                                assetVariables: $assetVariables,
                                providerFallback: [
                                    'from' => 'kling',
                                    'to' => 'openai',
                                    'reason' => Str::limit($klingError->getMessage(), 220, ''),
                                    'at' => now()->toDateTimeString(),
                                ]
                            );
                        }

                        $result['provider_fallback'] = [
                            'from' => 'kling',
                            'to' => $fallbackProvider,
                            'reason' => Str::limit($klingError->getMessage(), 220, ''),
                            'at' => now()->toDateTimeString(),
                            'nested' => $result['provider_fallback'] ?? null,
                        ];

                        return $finalizeVideoResult($result);
                    } catch (Throwable $fallbackError) {
                        $fallbackFailures[] = $fallbackProvider . ': ' . Str::limit($fallbackError->getMessage(), 220, '');

                        Log::warning('GenerateAiForContentItem video fallback failed', [
                            'content_item_id' => $item->id,
                            'from_provider' => 'kling',
                            'to_provider' => $fallbackProvider,
                            'error' => $fallbackError->getMessage(),
                        ]);
                    }
                }

                if (!empty($fallbackFailures)) {
                    throw new \RuntimeException(
                        $klingError->getMessage() . ' | VIDEO_PROVIDER_FALLBACKS_FAILED=' . implode(' || ', $fallbackFailures),
                        0,
                        $klingError
                    );
                }

                throw $klingError;
            } finally {
                foreach ($tempRefPaths as $tmpPath) {
                    if (is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath)) {
                        @unlink($tmpPath);
                    }
                }
            }
        }

        if ($videoProvider === 'runway') {
            try {
                return $finalizeVideoResult($this->generateVideoWithRunway(
                    runway: $runway,
                    openAi: $openAi,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $prompt,
                    videoPrompt: $runwayExecutionPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $referenceReason,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool,
                    validationReferenceAbsPool: $validationReferenceAbsPool,
                    mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $videoOptions
                ));
            } catch (Throwable $runwayError) {
                if (!$allowSecondaryVideoFallback || !$this->shouldFallbackFromRunwayToOpenAi($runwayError)) {
                    throw $runwayError;
                }

                try {
                    return $finalizeVideoResult($this->generateVideoWithOpenAi(
                        openAi: $openAi,
                        briefRaw: $briefRaw,
                        fallbackPrompt: $prompt,
                        videoPrompt: $this->buildOpenAiVideoFallbackPrompt($openAiExecutionPrompt, $briefRaw, $referencePaths),
                        referenceAbs: $referenceAbs,
                        referencePath: $referencePath,
                        referencePaths: $referencePaths,
                        referenceReason: $referenceReason . '_openai_fallback_after_runway_failure',
                        generationReferenceAbsPool: $generationReferenceAbsPool,
                        imageReferencePathPool: $imageReferencePathPool,
                        validationReferenceAbsPool: $validationReferenceAbsPool,
                        mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                        compositionMeta: $compositionMeta,
                        brandDecision: $brandDecision,
                        videoOptions: $videoOptions,
                        assetVariables: $assetVariables,
                        providerFallback: [
                            'from' => 'runway',
                            'to' => 'openai',
                            'reason' => Str::limit($runwayError->getMessage(), 220, ''),
                            'at' => now()->toDateTimeString(),
                        ]
                    ));
                } catch (Throwable $openAiFallbackError) {
                    if (!$this->shouldFallbackFromOpenAiToSecondaryProvider($openAiFallbackError)) {
                        throw $openAiFallbackError;
                    }

                    $secondaryProviders = array_values(array_filter(
                        $this->secondaryVideoProvidersForOpenAiFailure(!empty($generationReferenceAbsPool)),
                        fn ($provider) => $provider !== 'runway'
                    ));
                    $secondaryFailures = [];

                    foreach ($secondaryProviders as $secondaryProvider) {
                        try {
                            if ($secondaryProvider !== 'kling') {
                                continue;
                            }

                            $result = $this->generateVideoWithKling(
                                kling: $kling,
                                item: $item,
                                briefRaw: $briefRaw,
                                fallbackPrompt: $prompt,
                                videoPrompt: $klingExecutionPrompt,
                                referenceAbsPool: $generationReferenceAbsPool,
                                referencePaths: $imageReferencePathPool,
                                referenceReason: $referenceReason . '_kling_fallback_after_runway_openai_failure',
                                compositionMeta: $compositionMeta,
                                brandDecision: $brandDecision,
                                videoOptions: $videoOptions,
                                assetVariables: $assetVariables,
                                activeFeedbackRequest: $activeFeedbackRequest,
                                locationSequenceMode: $locationSequenceMode
                            );
                            $result['provider_fallback'] = [
                                'from' => 'runway',
                                'via' => 'openai',
                                'to' => 'kling',
                                'reason' => Str::limit($openAiFallbackError->getMessage(), 220, ''),
                                'at' => now()->toDateTimeString(),
                            ];

                            return $finalizeVideoResult($result);
                        } catch (Throwable $secondaryError) {
                            $secondaryFailures[] = $secondaryProvider . ': ' . Str::limit($secondaryError->getMessage(), 220, '');
                        }
                    }

                    if (!empty($secondaryFailures)) {
                        throw new RuntimeException(
                            $openAiFallbackError->getMessage() . ' | Secondary fallback failures: ' . implode(' | ', $secondaryFailures),
                            previous: $openAiFallbackError
                        );
                    }

                    throw $openAiFallbackError;
                }
            }
        }

        try {
            return $finalizeVideoResult($this->generateVideoWithOpenAi(
                openAi: $openAi,
                briefRaw: $briefRaw,
                fallbackPrompt: $prompt,
                videoPrompt: $videoProvider === 'openai' ? $openAiExecutionPrompt : $videoPrompt,
                referenceAbs: $referenceAbs,
                referencePath: $referencePath,
                referencePaths: $referencePaths,
                referenceReason: $referenceReason,
                generationReferenceAbsPool: $generationReferenceAbsPool,
                imageReferencePathPool: $imageReferencePathPool,
                validationReferenceAbsPool: $validationReferenceAbsPool,
                mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                compositionMeta: $compositionMeta,
                brandDecision: $brandDecision,
                videoOptions: $videoOptions,
                assetVariables: $assetVariables,
                providerFallback: null
            ));
        } catch (Throwable $openAiError) {
            if (!$allowSecondaryVideoFallback || !$this->shouldFallbackFromOpenAiToSecondaryProvider($openAiError)) {
                throw $openAiError;
            }

            $fallbackProviders = $this->secondaryVideoProvidersForOpenAiFailure(!empty($generationReferenceAbsPool));
            $fallbackFailures = [];

            foreach ($fallbackProviders as $fallbackProvider) {
                try {
                    if ($fallbackProvider === 'runway') {
                        $result = $this->generateVideoWithRunway(
                            runway: $runway,
                            openAi: $openAi,
                            item: $item,
                            briefRaw: $briefRaw,
                            fallbackPrompt: $prompt,
                            videoPrompt: $runwayExecutionPrompt,
                            referenceAbs: $referenceAbs,
                            referencePath: $referencePath,
                            referencePaths: $referencePaths,
                            referenceReason: $referenceReason . '_runway_fallback_after_openai_failure',
                            generationReferenceAbsPool: $generationReferenceAbsPool,
                            imageReferencePathPool: $imageReferencePathPool,
                            validationReferenceAbsPool: $validationReferenceAbsPool,
                            mustEnforceExplicitReferences: $mustValidateReferenceMatch,
                            compositionMeta: $compositionMeta,
                            brandDecision: $brandDecision,
                            videoOptions: $videoOptions
                        );
                    } else {
                        $result = $this->generateVideoWithKling(
                            kling: $kling,
                            item: $item,
                            briefRaw: $briefRaw,
                            fallbackPrompt: $prompt,
                            videoPrompt: $klingExecutionPrompt,
                            referenceAbsPool: $generationReferenceAbsPool,
                            referencePaths: $imageReferencePathPool,
                            referenceReason: $referenceReason . '_kling_fallback_after_openai_failure',
                            compositionMeta: $compositionMeta,
                            brandDecision: $brandDecision,
                            videoOptions: $videoOptions,
                            assetVariables: $assetVariables,
                            activeFeedbackRequest: $activeFeedbackRequest,
                            locationSequenceMode: $locationSequenceMode
                        );
                    }

                    $result['provider_fallback'] = [
                        'from' => 'openai',
                        'to' => $fallbackProvider,
                        'reason' => Str::limit($openAiError->getMessage(), 220, ''),
                        'at' => now()->toDateTimeString(),
                    ];

                    return $finalizeVideoResult($result);
                } catch (Throwable $fallbackError) {
                    $fallbackFailures[] = $fallbackProvider . ': ' . Str::limit($fallbackError->getMessage(), 220, '');

                    Log::warning('GenerateAiForContentItem video fallback failed', [
                        'content_item_id' => $item->id,
                        'from_provider' => 'openai',
                        'to_provider' => $fallbackProvider,
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            if (!empty($fallbackFailures)) {
                throw new \RuntimeException(
                    $openAiError->getMessage() . ' | VIDEO_PROVIDER_FALLBACKS_FAILED=' . implode(' || ', $fallbackFailures),
                    0,
                    $openAiError
                );
            }

            throw $openAiError;
        } finally {
            if (is_string($preparedRefPath) && $preparedRefPath !== '' && is_file($preparedRefPath)) {
                @unlink($preparedRefPath);
            }
            foreach ($tempRefPaths as $tmpPath) {
                if (is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath)) {
                    @unlink($tmpPath);
                }
            }
        }
    }

    /**
     * Durata totale richiesta per il video finale. Se supera il limite del provider,
     * la pipeline entra in modalita segmentata e concatena piu clip coerenti.
     */
    public function targetTotalVideoSecondsForItem(ContentItem $item, string $provider): int
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $candidates = [
            (int) data_get($meta, 'requested_video_duration_seconds', 0),
            (int) data_get($meta, 'video_duration_seconds_requested', 0),
            (int) data_get($meta, 'video_duration_seconds', 0),
            (int) data_get($meta, 'reel_duration_seconds', 0),
            (int) data_get($meta, 'video_generation.target_total_seconds', 0),
        ];

        $target = 0;
        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                $target = $candidate;
                break;
            }
        }

        if ($target <= 0) {
            $brief = trim((string) data_get($meta, 'manual_brief', (string) ($item->caption ?? '')));
            if ($brief !== '' && preg_match('/\b(\d{1,2})\s*(secondi|secondo|sec)\b/iu', $brief, $matches) === 1) {
                $target = (int) ($matches[1] ?? 0);
            }
        }

        if ($target <= 0) {
            $target = (int) $this->targetVideoSecondsForFormat($item);
        }

        $providerMax = $this->providerSingleClipMaxSeconds($provider);

        return max(3, min(45, max($target, $providerMax > 0 ? min($providerMax, $target) : $target)));
    }

    /**
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    public function buildExtendedVideoSingleClipFallback(
        string $provider,
        int $targetTotalSeconds,
        array $videoOptions,
        string $ffmpegBinary
    ): array {
        return [
            'mode' => 'single_clip_fallback',
            'reason' => 'ffmpeg_unavailable',
            'provider' => strtolower(trim($provider)),
            'model' => trim((string) ($videoOptions['model'] ?? '')),
            'requested_total_seconds' => $targetTotalSeconds,
            'delivered_seconds' => $this->providerSingleClipMaxSeconds($provider, (string) ($videoOptions['model'] ?? '')),
            'size' => (string) ($videoOptions['size'] ?? ''),
            'ffmpeg_binary' => trim($ffmpegBinary) !== '' ? trim($ffmpegBinary) : null,
            'at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $extendedVideoFallback
     * @return array<string, mixed>
     */
    public function applyExtendedVideoSingleClipFallback(array $result, ?array $extendedVideoFallback): array
    {
        if ($extendedVideoFallback === null) {
            return $result;
        }

        $resolvedProvider = strtolower(trim((string) ($result['provider'] ?? $extendedVideoFallback['provider'] ?? 'openai')));
        $requestedTotalSeconds = (int) ($extendedVideoFallback['requested_total_seconds'] ?? 0);
        $fallbackDeliveredSeconds = (int) ($extendedVideoFallback['delivered_seconds'] ?? 0);
        $resolvedModel = trim((string) data_get($result, 'request_summary.model', (string) ($extendedVideoFallback['model'] ?? '')));
        $resolvedDeliveredSeconds = $this->normalizeVideoSecondsForProvider(
            $resolvedProvider,
            $fallbackDeliveredSeconds > 0 ? $fallbackDeliveredSeconds : $requestedTotalSeconds,
            $resolvedModel
        );

        $requestSummary = is_array($result['request_summary'] ?? null) ? $result['request_summary'] : [];
        $requestSummary['mode'] = 'single_clip_fallback';
        $requestSummary['extended_requested'] = true;
        $requestSummary['fallback_reason'] = (string) ($extendedVideoFallback['reason'] ?? 'ffmpeg_unavailable');
        $requestSummary['target_total_seconds'] = $requestedTotalSeconds;
        $requestSummary['delivered_seconds'] = $resolvedDeliveredSeconds;

        $result['request_summary'] = $requestSummary;
        $result['extended'] = false;
        $result['segment_count'] = 1;
        $result['target_total_seconds'] = $requestedTotalSeconds;
        $result['segments'] = [];
        $result['extended_fallback'] = array_merge($extendedVideoFallback, [
            'provider' => $resolvedProvider,
            'model' => $resolvedModel !== '' ? $resolvedModel : ($extendedVideoFallback['model'] ?? null),
            'delivered_seconds' => $resolvedDeliveredSeconds,
        ]);

        return $result;
    }

    public function shouldGenerateExtendedVideo(ContentItem $item, string $provider, int $targetTotalSeconds, string $model = ''): bool
    {
        if (!$this->isVideoFormat($item)) {
            return false;
        }

        return $targetTotalSeconds > $this->providerSingleClipMaxSeconds($provider, $model);
    }

    public function providerSingleClipMaxSeconds(string $provider, string $model = ''): int
    {
        return $this->capabilityRegistry()->maxVideoDuration($provider, $model);
    }

    /**
     * @return array<int, int>
     */
    public function segmentDurationsForExtendedVideo(string $provider, int $targetTotalSeconds, string $model = ''): array
    {
        $provider = strtolower(trim($provider));

        if ($provider === 'kling') {
            $durations = [];
            $remaining = max(0, $targetTotalSeconds);
            while ($remaining > 10) {
                $durations[] = 10;
                $remaining -= 10;
            }
            if ($remaining > 0) {
                $durations[] = $remaining > 5 ? 10 : 5;
            }

            return array_values(array_filter($durations, fn ($seconds) => $seconds >= 5));
        }

        if ($provider === 'openai') {
            $allowed = [12, 8, 4];
            $durations = [];
            $remaining = max(0, $targetTotalSeconds);

            while ($remaining > 12) {
                $durations[] = 12;
                $remaining -= 12;
            }

            if ($remaining > 0) {
                foreach ($allowed as $option) {
                    if ($remaining >= $option) {
                        $durations[] = $option;
                        $remaining -= $option;
                        break;
                    }
                }
            }

            if ($remaining > 0 && !empty($durations)) {
                $durations[count($durations) - 1] = min(12, $durations[count($durations) - 1] + $remaining);
            }

            return array_values(array_filter($durations, fn ($seconds) => in_array($seconds, [4, 8, 12], true)));
        }

        if ($provider === 'google_veo') {
            return $this->discreteSegmentDurations([4, 6, 8], $targetTotalSeconds);
        }

        if ($provider === 'runway' && $this->isRunwayVeoModel($model)) {
            return $this->discreteSegmentDurations([4, 6, 8], $targetTotalSeconds);
        }

        $max = $this->providerSingleClipMaxSeconds($provider, $model);
        $segmentCount = (int) ceil(max(1, $targetTotalSeconds) / max(1, $max));
        $segmentCount = max(2, $segmentCount);
        $base = intdiv($targetTotalSeconds, $segmentCount);
        $remainder = $targetTotalSeconds % $segmentCount;
        $durations = [];

        for ($i = 0; $i < $segmentCount; $i++) {
            $seconds = $base + ($i < $remainder ? 1 : 0);
            $durations[] = max(3, min($max, $seconds));
        }

        return $durations;
    }

    /**
     * @param  array<int, int>  $allowed
     * @return array<int, int>
     */
    public function discreteSegmentDurations(array $allowed, int $targetTotalSeconds): array
    {
        $allowed = array_values(array_unique(array_filter(
            array_map(fn ($value) => (int) $value, $allowed),
            fn (int $value) => $value > 0
        )));

        if (empty($allowed)) {
            return [];
        }

        sort($allowed);
        $min = $allowed[0];
        $max = $allowed[count($allowed) - 1];
        $target = max($min, $targetTotalSeconds);
        $limit = $target + $max;
        $paths = array_fill(0, $limit + 1, null);
        $paths[0] = [];

        for ($sum = 0; $sum <= $limit; $sum++) {
            if (!is_array($paths[$sum])) {
                continue;
            }

            foreach ($allowed as $duration) {
                $next = $sum + $duration;
                if ($next > $limit) {
                    continue;
                }

                $candidate = array_merge($paths[$sum], [$duration]);
                if (!is_array($paths[$next]) || count($candidate) < count($paths[$next])) {
                    $paths[$next] = $candidate;
                }
            }
        }

        $best = null;
        $bestSum = null;
        for ($sum = $target; $sum <= $limit; $sum++) {
            if (!is_array($paths[$sum])) {
                continue;
            }

            if ($best === null || $sum < $bestSum || ($sum === $bestSum && count($paths[$sum]) < count($best))) {
                $best = $paths[$sum];
                $bestSum = $sum;
            }
        }

        if (!is_array($best)) {
            return [$min];
        }

        rsort($best);

        return array_values($best);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    public function buildExtendedVideoSegmentPrompts(
        string $provider,
        ContentItem $item,
        array $meta,
        string $basePrompt,
        int $segmentCount
    ): array {
        $segmentCount = max(2, $segmentCount);
        $blueprint = is_array(data_get($meta, 'reel_blueprint', []))
            ? (array) data_get($meta, 'reel_blueprint', [])
            : [];
        $storyboard = is_array(data_get($meta, 'storyboard_meta', []))
            ? (array) data_get($meta, 'storyboard_meta', [])
            : [];
        $hook = trim((string) ($blueprint['hook'] ?? ''));
        $continuityLock = trim((string) ($blueprint['continuity_lock'] ?? ''));
        $visualPayoff = trim((string) ($blueprint['visual_payoff'] ?? ''));
        $shotChunks = $this->chunkReelBlueprintShots((array) ($blueprint['shots'] ?? []), $segmentCount);
        $sceneChunks = $this->chunkStoryboardScenes((array) data_get($storyboard, 'scene_list', []), $segmentCount);
        $limit = $this->videoPromptCharLimitForProvider($provider);
        $prompts = [];

        for ($index = 0; $index < $segmentCount; $index++) {
            $humanIndex = $index + 1;
            $isFirst = $index === 0;
            $isLast = $humanIndex === $segmentCount;
            $parts = [$basePrompt];
            $parts[] = "Long reel assembly rule: this is segment {$humanIndex} of {$segmentCount} for one single final reel.";

            if ($isFirst) {
                $parts[] = 'Open with the hook immediately and establish the scene anchors in a premium, native-Instagram way.';
                if ($hook !== '') {
                    $parts[] = "Opening hook to preserve: {$hook}.";
                }
            } elseif ($isLast) {
                $parts[] = 'Continue naturally from the previous segment and close with a strong visual payoff, not with a fade or unresolved motion.';
                if ($visualPayoff !== '') {
                    $parts[] = "Final payoff to preserve: {$visualPayoff}.";
                }
            } else {
                $parts[] = 'This is a middle continuation segment: maintain continuity and evolve camera, gesture and framing without changing subject identity.';
            }

            if ($continuityLock !== '') {
                $parts[] = "Continuity lock: {$continuityLock}.";
            }

            $sceneSummary = $this->summarizeStoryboardSceneChunk($sceneChunks[$index] ?? []);
            if ($sceneSummary !== '') {
                $parts[] = "Scene plan for this segment: {$sceneSummary}.";
            }

            $shotSummary = $this->summarizeReelShotChunk($shotChunks[$index] ?? []);
            if ($shotSummary !== '') {
                $parts[] = "Shot focus for this segment: {$shotSummary}.";
            }

            if (!$isLast) {
                $parts[] = 'End this segment with a clean visual bridge so the next segment can be stitched without a confusing jump cut.';
            } else {
                $parts[] = 'Final frame must feel conclusive, premium and ready to stop the reel.';
            }

            if (strtolower(trim((string) ($item->format ?? ''))) === 'reel') {
                $parts[] = 'Keep the pacing bold and readable like a real Instagram reel, not a slow showcase clip.';
            }

            $prompts[] = Str::limit(
                trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))),
                $limit,
                ''
            );
        }

        return $prompts;
    }

    /**
     * @param  array<string, mixed>  $storyboard
     * @return array<string, mixed>|null
     */
    public function compactStoryboardSummary(array $storyboard): ?array
    {
        if (empty((array) ($storyboard['scene_list'] ?? []))) {
            return null;
        }

        $scenes = collect((array) ($storyboard['scene_list'] ?? []))
            ->filter(fn ($scene) => is_array($scene))
            ->map(function (array $scene): array {
                return [
                    'scene_index' => (int) ($scene['scene_index'] ?? 0),
                    'scene_type' => Str::limit(trim((string) ($scene['scene_type'] ?? '')), 24, ''),
                    'duration_target' => (int) ($scene['duration_target'] ?? 0),
                    'overlay_safe_area' => (string) data_get($scene, 'text_overlay.safe_area', ''),
                    'cta_role' => (string) ($scene['cta_role'] ?? $scene['CTA_role'] ?? ''),
                ];
            })
            ->values()
            ->all();

        return [
            'version' => (string) ($storyboard['version'] ?? ''),
            'scene_count' => count($scenes),
            'total_duration_ms' => (int) ($storyboard['total_duration_ms'] ?? 0),
            'hook_scene_present' => (bool) ($storyboard['hook_scene_present'] ?? false),
            'cta_scene_present' => (bool) ($storyboard['cta_scene_present'] ?? false),
            'identity_first' => (bool) ($storyboard['identity_first'] ?? false),
            'scenes' => $scenes,
        ];
    }

    /**
     * @param  array<int, mixed>  $shots
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function chunkReelBlueprintShots(array $shots, int $segmentCount): array
    {
        $shots = array_values(array_filter($shots, fn ($shot) => is_array($shot)));
        if (empty($shots)) {
            return array_fill(0, max(2, $segmentCount), []);
        }

        $segmentCount = max(2, $segmentCount);
        $chunkSize = (int) ceil(count($shots) / $segmentCount);
        $chunks = [];

        for ($index = 0; $index < $segmentCount; $index++) {
            $chunks[] = array_slice($shots, $index * $chunkSize, $chunkSize);
        }

        return $chunks;
    }

    /**
     * @param  array<int, mixed>  $scenes
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function chunkStoryboardScenes(array $scenes, int $segmentCount): array
    {
        $scenes = array_values(array_filter($scenes, fn ($scene) => is_array($scene)));
        if (empty($scenes)) {
            return array_fill(0, max(2, $segmentCount), []);
        }

        $segmentCount = max(2, $segmentCount);
        $chunkSize = (int) ceil(count($scenes) / $segmentCount);
        $chunks = [];

        for ($index = 0; $index < $segmentCount; $index++) {
            $chunks[] = array_slice($scenes, $index * $chunkSize, $chunkSize);
        }

        return $chunks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $shots
     */
    public function summarizeReelShotChunk(array $shots): string
    {
        if (empty($shots)) {
            return '';
        }

        $parts = [];
        foreach ($shots as $shot) {
            if (!is_array($shot)) {
                continue;
            }

            $summary = implode(', ', array_values(array_filter([
                trim((string) ($shot['purpose'] ?? '')),
                trim((string) ($shot['subject'] ?? '')),
                trim((string) ($shot['camera'] ?? '')),
                trim((string) ($shot['motion'] ?? '')),
            ])));

            if ($summary !== '') {
                $parts[] = $summary;
            }
        }

        return Str::limit(implode(' | ', $parts), 420, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    public function summarizeStoryboardSceneChunk(array $scenes): string
    {
        if (empty($scenes)) {
            return '';
        }

        $parts = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $summary = implode(', ', array_values(array_filter([
                trim((string) ($scene['scene_type'] ?? '')),
                trim((string) ($scene['shot_objective'] ?? '')),
                trim((string) data_get($scene, 'text_overlay.safe_area', '')),
            ])));

            if ($summary !== '') {
                $parts[] = $summary;
            }
        }

        return Str::limit(implode(' | ', $parts), 420, '');
    }

    public function videoPromptCharLimitForProvider(string $provider): int
    {
        return match (strtolower(trim($provider))) {
            'runway' => max(600, min(1600, (int) (config('runway.max_prompt_chars') ?: 1400))),
            'google_veo' => max(600, min(2000, (int) (config('google_veo.max_prompt_chars') ?: 1600))),
            'kling' => max(400, min(1800, (int) (config('kling.max_prompt_chars') ?: 1400))),
            default => 1800,
        };
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $activeFeedbackRequest
     * @return array<string, mixed>
     */
    public function generateExtendedVideoAsset(
        OpenAiService $openAi,
        RunwayService $runway,
        KlingService $kling,
        GoogleVeoService $googleVeo,
        ContentItem $item,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoProvider,
        string $runwayExecutionPrompt,
        string $klingExecutionPrompt,
        string $googleVeoExecutionPrompt,
        string $openAiExecutionPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referencePaths,
        string $referenceReason,
        array $generationReferenceAbsPool,
        array $imageReferencePathPool,
        array $validationReferenceAbsPool,
        bool $mustEnforceExplicitReferences,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $locationSequenceMode,
        int $targetTotalSeconds
    ): array {
        $ffmpeg = $this->resolveFfmpegBinary();
        if (!$this->canRunBinary($ffmpeg)) {
            throw new \RuntimeException('La durata richiesta supera il limite del provider ma FFmpeg non e disponibile per unire i segmenti.');
        }

        $segmentDurations = $this->segmentDurationsForExtendedVideo($videoProvider, $targetTotalSeconds, (string) ($videoOptions['model'] ?? ''));
        if (count($segmentDurations) < 2) {
            throw new \RuntimeException('Impossibile costruire un piano segmenti valido per il reel esteso richiesto.');
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $basePrompt = match ($videoProvider) {
            'runway' => $runwayExecutionPrompt,
            'google_veo' => $googleVeoExecutionPrompt,
            'kling' => $klingExecutionPrompt,
            default => $openAiExecutionPrompt,
        };
        $storyboardChunks = $this->chunkStoryboardScenes((array) data_get($meta, 'storyboard_meta.scene_list', []), count($segmentDurations));
        $segmentPrompts = $this->buildExtendedVideoSegmentPrompts(
            provider: $videoProvider,
            item: $item,
            meta: $meta,
            basePrompt: $basePrompt,
            segmentCount: count($segmentDurations)
        );

        $segments = [];
        $segmentVideoPaths = [];
        $thumbnailPath = '';
        $referencePathOut = '';
        $referencePathsOut = [];
        $generationAttempts = 0;
        $videoIds = [];

        foreach ($segmentDurations as $index => $segmentSeconds) {
            $segmentPrompt = (string) ($segmentPrompts[$index] ?? end($segmentPrompts) ?: $basePrompt);
            $segmentVideoOptions = $videoOptions;
            $segmentVideoOptions['seconds'] = $segmentSeconds;
            $segmentReferenceReason = $referenceReason . '_segment_' . ($index + 1);

            $segmentResult = match ($videoProvider) {
                'runway' => $this->generateVideoWithRunway(
                    runway: $runway,
                    openAi: $openAi,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $fallbackPrompt,
                    videoPrompt: $segmentPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $segmentReferenceReason,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool,
                    validationReferenceAbsPool: $validationReferenceAbsPool,
                    mustEnforceExplicitReferences: $mustEnforceExplicitReferences,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $segmentVideoOptions
                ),
                'kling' => $this->generateVideoWithKling(
                    kling: $kling,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $fallbackPrompt,
                    videoPrompt: $segmentPrompt,
                    referenceAbs: is_string($referenceAbs) ? $referenceAbs : null,
                    referencePath: is_string($referencePath) ? $referencePath : null,
                    referenceAbsPool: $generationReferenceAbsPool,
                    referencePaths: $imageReferencePathPool,
                    referenceReason: $segmentReferenceReason,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $segmentVideoOptions,
                    assetVariables: $assetVariables,
                    activeFeedbackRequest: $activeFeedbackRequest,
                    locationSequenceMode: $locationSequenceMode
                ),
                'google_veo' => $this->generateVideoWithGoogleVeo(
                    googleVeo: $googleVeo,
                    item: $item,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $fallbackPrompt,
                    videoPrompt: $segmentPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $segmentReferenceReason,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $segmentVideoOptions,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool
                ),
                default => $this->generateVideoWithOpenAi(
                    openAi: $openAi,
                    briefRaw: $briefRaw,
                    fallbackPrompt: $fallbackPrompt,
                    videoPrompt: $segmentPrompt,
                    referenceAbs: $referenceAbs,
                    referencePath: $referencePath,
                    referencePaths: $referencePaths,
                    referenceReason: $segmentReferenceReason,
                    generationReferenceAbsPool: $generationReferenceAbsPool,
                    imageReferencePathPool: $imageReferencePathPool,
                    validationReferenceAbsPool: $validationReferenceAbsPool,
                    mustEnforceExplicitReferences: $mustEnforceExplicitReferences,
                    compositionMeta: $compositionMeta,
                    brandDecision: $brandDecision,
                    videoOptions: $segmentVideoOptions,
                    assetVariables: $assetVariables,
                    providerFallback: null
                ),
            };

            $segmentVideoPath = trim((string) ($segmentResult['video_path'] ?? ''));
            if ($segmentVideoPath === '') {
                throw new \RuntimeException('Uno dei segmenti del reel esteso non ha prodotto un video salvato.');
            }

            $segmentPlayback = [
                'applied' => false,
                'reason' => 'provider_passthrough',
                'provider' => $videoProvider,
                'input_video_path' => $segmentVideoPath,
                'video_path' => $segmentVideoPath,
                'error' => null,
            ];
            if ($videoProvider === 'runway') {
                $segmentPlayback = $this->postProcessGeneratedVideoForPlayback($item, $segmentVideoPath, $videoProvider);
                if (!empty($segmentPlayback['video_path']) && is_string($segmentPlayback['video_path'])) {
                    $segmentVideoPath = (string) $segmentPlayback['video_path'];
                }
            }

            $segmentVideoPaths[] = $segmentVideoPath;
            $generationAttempts += (int) ($segmentResult['generation_attempts'] ?? 1);

            $segmentVideoId = trim((string) ($segmentResult['video_id'] ?? ''));
            if ($segmentVideoId !== '') {
                $videoIds[] = $segmentVideoId;
            }

            if ($thumbnailPath === '') {
                $thumbnailPath = trim((string) ($segmentResult['thumbnail_path'] ?? ''));
            }
            if ($referencePathOut === '') {
                $referencePathOut = trim((string) ($segmentResult['reference_path'] ?? ''));
            }
            if (empty($referencePathsOut)) {
                $referencePathsOut = array_values(array_filter(
                    (array) ($segmentResult['reference_paths'] ?? []),
                    fn ($value) => is_string($value) && trim($value) !== ''
                ));
            }

            $segments[] = [
                'index' => $index + 1,
                'seconds' => $segmentSeconds,
                'provider' => $videoProvider,
                'video_id' => $segmentVideoId,
                'video_path' => $segmentVideoPath,
                'thumbnail_path' => trim((string) ($segmentResult['thumbnail_path'] ?? '')),
                'reference_reason' => trim((string) ($segmentResult['reference_reason'] ?? $segmentReferenceReason)),
                'storyboard_scene_indexes' => array_values(array_filter(array_map(
                    fn ($scene) => is_array($scene) ? (int) ($scene['scene_index'] ?? 0) : null,
                    (array) ($storyboardChunks[$index] ?? [])
                ))),
                'request_summary' => $segmentResult['request_summary'] ?? null,
                'playback_postprocess' => $segmentPlayback,
            ];
        }

        $stitchedVideoPath = $this->concatenateVideoSegments($segmentVideoPaths);

        return [
            'source' => $videoProvider . '_extended_video_generation',
            'provider' => $videoProvider,
            'video_id' => implode('+', $videoIds),
            'video_path' => $stitchedVideoPath,
            'thumbnail_path' => $thumbnailPath,
            'reference_path' => $referencePathOut,
            'reference_paths' => $referencePathsOut,
            'reference_reason' => $referenceReason . '_extended_' . count($segments) . '_segments',
            'reference_validation' => null,
            'composition_reference' => $compositionMeta,
            'generation_attempts' => $generationAttempts,
            'job_status' => 'completed',
            'brand_selection' => $brandDecision,
            'provider_fallback' => null,
            'request_summary' => [
                'mode' => 'extended_reel',
                'provider' => $videoProvider,
                'target_total_seconds' => $targetTotalSeconds,
                'segment_count' => count($segments),
                'segment_durations' => $segmentDurations,
                'size' => (string) ($videoOptions['size'] ?? ''),
                'storyboard' => $this->compactStoryboardSummary((array) data_get($meta, 'storyboard_meta', [])),
            ],
            'reference_input_summary' => [
                'requested_reference_count' => count($imageReferencePathPool),
                'active_reference_count' => count($referencePathsOut),
                'reference_reason' => $referenceReason,
                'extended_mode' => true,
            ],
            'extended' => true,
            'segment_count' => count($segments),
            'target_total_seconds' => $targetTotalSeconds,
            'segments' => $segments,
            'skip_playback_postprocess' => $videoProvider === 'runway',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    public function buildStrategicVideoPrompt(
        ContentItem $item,
        array $meta,
        string $briefRaw,
        ?string $selectedBrandImageAbs,
        array $generationReferenceAbsPool,
        array $referencePaths,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $logoRequested,
        ?string $logoAbs,
        string $logoMode,
        bool $locationSequenceMode,
        bool $mustEnforceExplicitReferences
    ): string {
        $storedVideoPrompt = trim((string) data_get($meta, 'video_prompt', ''));
        if ($storedVideoPrompt !== '') {
            $parts = [$storedVideoPrompt];
        } else {
            $brandName = trim((string) data_get($meta, 'tenant_profile.business_name', 'Brand'));
            $industry = trim((string) data_get($meta, 'tenant_profile.industry', ''));
            $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', 'Awareness')));
            $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
            $parts = [
                "Crea un reel video verticale 9:16 per {$brandName}.",
                $industry !== '' ? "Settore: {$industry}." : '',
                $briefRaw !== '' ? "Brief prioritario: {$briefRaw}." : '',
                $objective !== '' ? "Obiettivo: {$objective}." : '',
                $tone !== '' ? "Tono del brand: {$tone}." : '',
            ];
        }

        $parts[] = 'Video social realistico, elegante e vendibile, pensato per Instagram Reel.';
        $parts[] = 'Apri con un primo secondo forte e dinamico, poi accompagna la scena con movimenti fluidi e naturali.';
        $parts[] = 'Niente testo in sovraimpressione, niente watermark, niente loghi inventati, niente look da spot corporate.';
        $parts[] = 'Se compaiono persone, devono sembrare reali, spontanee e coerenti con il contesto.';
        $parts[] = $this->personIdentityVideoInstruction($assetVariables);
        $parts[] = $this->subjectLockVideoInstruction(
            $meta,
            $this->videoSubjectContextText($meta, $briefRaw, (string) data_get($meta, 'video_prompt', '')),
            $assetVariables
        );
        $parts[] = $this->feedbackDrivenVideoInstruction($activeFeedbackRequest, $assetVariables, $locationSequenceMode);
        $assetIdentityHint = $this->buildAssetIdentityPromptHint((array) data_get($meta, 'asset_identity', []));
        if ($assetIdentityHint !== '') {
            $parts[] = 'Vincoli identitari: ' . $assetIdentityHint . '.';
        }
        $storyboardHint = $this->storyboardPromptInstruction((array) data_get($meta, 'storyboard_meta', []));
        if ($storyboardHint !== '') {
            $parts[] = $storyboardHint;
        }

        if ($locationSequenceMode) {
            $locationNames = $this->videoLocationSequenceNames($assetVariables);
            if (!empty($locationNames)) {
                $parts[] = 'Le aree reali da mostrare sono: ' . implode(', ', $locationNames) . '.';
            }
            $parts[] = 'Queste location sono ambienti reali diversi dello stesso locale: mostrali in sequenza come scene separate e riconoscibili.';
            $parts[] = 'Non fonderli in un unica stanza, non inventare nuove sale, non cambiare architettura, prospettiva o layout del posto.';
            $parts[] = 'Usa transizioni naturali tra un ambiente e l altro come in un reel editoriale premium.';
        } elseif (!empty($generationReferenceAbsPool)) {
            if (count($referencePaths) > 1) {
                $parts[] = 'Prendi i riferimenti come base narrativa coerente senza trasformarli in un collage o in una stanza impossibile.';
            } else {
                $parts[] = 'Mantieni il DNA visivo reale del luogo o del soggetto di riferimento, migliorando ritmo e resa video.';
            }
        } elseif ($selectedBrandImageAbs) {
            $parts[] = 'Riprendi il tema del brand in modo creativo senza sembrare una foto statica animata.';
        }

        if ($this->hasProtectedLocationEnvelope($assetVariables, $referencePaths)) {
            $parts[] = 'Il luogo reale deve restare autentico e riconoscibile.';
        }

        if ($logoRequested && $logoAbs) {
            $parts[] = $logoMode === 'background'
                ? 'Se usi il logo reale, tienilo discreto sullo sfondo senza farlo dominare la scena.'
                : 'Se usi il logo reale, integralo in modo naturale e plausibile nella scena.';
        }

        if ($mustEnforceExplicitReferences) {
            $parts[] = 'Usa i riferimenti richiesti dall utente in modo riconoscibile, senza sostituire i soggetti principali con alternative casuali.';
        }

        $parts[] = $this->videoOutputLanguageInstruction($meta, $briefRaw);

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 900, '');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function videoOutputLanguageInstruction(array $meta, string $briefRaw, bool $english = false): string
    {
        $language = $this->detectExplicitVideoOutputLanguage([
            $briefRaw,
            (string) data_get($meta, 'manual_brief', ''),
            (string) data_get($meta, 'video_prompt', ''),
            (string) data_get($meta, 'voiceover', ''),
            (string) data_get($meta, 'caption', ''),
            (string) data_get($meta, 'title', ''),
        ]);

        if ($language !== null && $language !== 'it') {
            $label = $this->videoOutputLanguageLabel($language, $english);

            return $english
                ? "Requested output language: {$label}. Keep dialogue, voice, subtitles, readable signage and any visible text in {$label} because the brief explicitly asks for it."
                : "Lingua output richiesta: {$label}. Mantieni dialoghi, voce, sottotitoli, insegne leggibili e testo visibile in {$label} perche il brief lo richiede esplicitamente.";
        }

        return $english
            ? 'Default output language: Italian. If there is dialogue, voice, subtitles, readable signage or any visible text, it must be natural Italian unless the user explicitly asked for another language.'
            : 'Lingua output predefinita: italiano naturale. Se compaiono dialoghi, voce, sottotitoli, insegne leggibili o testo visibile, devono essere in italiano corretto salvo richiesta esplicita diversa nel brief.';
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function detectExplicitVideoOutputLanguage(array $texts): ?string
    {
        $source = Str::lower(trim(implode(' ', array_filter(
            array_map(fn ($value) => is_string($value) ? trim($value) : '', $texts),
            fn ($value) => $value !== ''
        ))));

        if ($source === '') {
            return null;
        }

        $patterns = [
            'en' => ['/\\bin english\\b/u', '/\\benglish\\b/u', '/\\bin inglese\\b/u', '/\\binglese\\b/u'],
            'es' => ['/\\bin spanish\\b/u', '/\\bspanish\\b/u', '/\\bin spagnolo\\b/u', '/\\bspagnolo\\b/u'],
            'fr' => ['/\\bin french\\b/u', '/\\bfrench\\b/u', '/\\bin francese\\b/u', '/\\bfrancese\\b/u'],
            'de' => ['/\\bin german\\b/u', '/\\bgerman\\b/u', '/\\bin tedesco\\b/u', '/\\btedesco\\b/u'],
            'pt' => ['/\\bin portuguese\\b/u', '/\\bportuguese\\b/u', '/\\bin portoghese\\b/u', '/\\bportoghese\\b/u'],
            'it' => ['/\\bin italian\\b/u', '/\\bitalian\\b/u', '/\\bin italiano\\b/u', '/\\bitaliano\\b/u'],
        ];

        foreach (['en', 'es', 'fr', 'de', 'pt'] as $language) {
            foreach ($patterns[$language] as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    return $language;
                }
            }
        }

        foreach ($patterns['it'] as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return 'it';
            }
        }

        return null;
    }

    private function videoOutputLanguageLabel(string $language, bool $english = false): string
    {
        return match ($language) {
            'en' => $english ? 'English' : 'inglese',
            'es' => $english ? 'Spanish' : 'spagnolo',
            'fr' => $english ? 'French' : 'francese',
            'de' => $english ? 'German' : 'tedesco',
            'pt' => $english ? 'Portuguese' : 'portoghese',
            default => $english ? 'Italian' : 'italiano',
        };
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>
     */
    public function normalizeReelBlueprint(
        array $blueprint,
        ContentItem $item,
        array $meta,
        array $assetVariables,
        string $videoPrompt
    ): array {
        if (Str::lower(trim((string) ($item->format ?? 'post'))) !== 'reel') {
            return [];
        }

        $fallback = $this->fallbackReelBlueprint($item, $meta, $assetVariables, $videoPrompt);
        if (empty($blueprint)) {
            return $fallback;
        }

        $shots = [];
        foreach (array_slice((array) ($blueprint['shots'] ?? []), 0, 4) as $index => $shot) {
            if (!is_array($shot)) {
                continue;
            }

            $purpose = Str::limit(trim((string) ($shot['purpose'] ?? '')), 120, '');
            $subject = Str::limit(trim((string) ($shot['subject'] ?? '')), 120, '');
            $camera = Str::limit(trim((string) ($shot['camera'] ?? '')), 90, '');
            $motion = Str::limit(trim((string) ($shot['motion'] ?? '')), 90, '');

            if ($purpose === '' && $subject === '' && $camera === '' && $motion === '') {
                continue;
            }

            $shots[] = [
                'order' => max(1, (int) ($shot['order'] ?? ($index + 1))),
                'purpose' => $purpose !== '' ? $purpose : (string) data_get($fallback, "shots.{$index}.purpose", ''),
                'subject' => $subject !== '' ? $subject : (string) data_get($fallback, "shots.{$index}.subject", ''),
                'camera' => $camera !== '' ? $camera : (string) data_get($fallback, "shots.{$index}.camera", ''),
                'motion' => $motion !== '' ? $motion : (string) data_get($fallback, "shots.{$index}.motion", ''),
            ];
        }

        if (count($shots) < 3) {
            $shots = (array) ($fallback['shots'] ?? []);
        }

        return [
            'hook' => Str::limit(trim((string) ($blueprint['hook'] ?? '')), 140, '') ?: (string) ($fallback['hook'] ?? ''),
            'anchor_frame' => Str::limit(trim((string) ($blueprint['anchor_frame'] ?? '')), 160, '') ?: (string) ($fallback['anchor_frame'] ?? ''),
            'continuity_lock' => Str::limit(trim((string) ($blueprint['continuity_lock'] ?? '')), 220, '') ?: (string) ($fallback['continuity_lock'] ?? ''),
            'visual_payoff' => Str::limit(trim((string) ($blueprint['visual_payoff'] ?? '')), 140, '') ?: (string) ($fallback['visual_payoff'] ?? ''),
            'shots' => array_values($shots),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>
     */
    public function fallbackReelBlueprint(
        ContentItem $item,
        array $meta,
        array $assetVariables,
        string $videoPrompt
    ): array {
        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', 'awareness')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $mainHook = trim((string) data_get($meta, 'item_brain.hook_meta.main_hook', ''));
        $videoSegments = (array) data_get($meta, 'item_brain.content_structure_meta.video_segments', []);
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $controlContext = $this->videoSubjectContextText($meta, (string) data_get($meta, 'manual_brief', ''), $videoPrompt);
        $needsDualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $controlContext, $assetVariables);
        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $controlContext));
        $productLabel = $productLabel !== '' ? $productLabel : 'il veicolo o prodotto reale richiesto';

        if ($needsDualSubjectLock) {
            $mainSubject = "la persona reale del brand insieme a {$productLabel}";
            $anchor = "hero frame verticale con la persona reale del brand e {$productLabel} chiaramente visibili nello stesso shot";
            $continuity = "mantieni sempre la stessa persona, stessi lineamenti e {$productLabel} con marca, modello e colore coerenti tra gli shot";
        } else {
            $mainSubject = $hasPersonVariable
                ? 'la persona reale del brand, uguale ai riferimenti'
                : 'il soggetto principale del contenuto, riconoscibile da subito';
            $anchor = $hasPersonVariable
                ? 'medium shot verticale della persona reale del brand nel contesto vero, subito riconoscibile'
                : 'hero frame verticale pulito del soggetto principale nel contesto reale del brand';
            $continuity = $hasPersonVariable
                ? 'mantieni sempre la stessa persona, stesso volto, stessi lineamenti e stessa presenza'
                : 'mantieni lo stesso spazio, lo stesso soggetto principale e la stessa palette del brand';
        }

        $payoff = $angle !== ''
            ? "chiusura pulita che fa percepire {$angle}"
            : 'chiusura pulita con payoff visivo coerente con il brand';
        $hook = $objective !== ''
            ? "apertura forte entro il primo secondo per far percepire {$objective}"
            : 'apertura forte entro il primo secondo con soggetto chiaro e leggibile';
        if (trim((string) ($videoSegments['payoff_reveal'] ?? '')) !== '') {
            $payoff = trim((string) $videoSegments['payoff_reveal']);
        }
        if (trim((string) ($videoSegments['hook_0_3'] ?? '')) !== '') {
            $hook = trim((string) $videoSegments['hook_0_3']);
        } elseif ($mainHook !== '') {
            $hook = $mainHook;
        }
        $toneHint = $tone !== '' ? "con tono {$tone}" : 'con tono coerente al brand';

        $shotTwoSubject = $needsDualSubjectLock
            ? "{$productLabel} in evidenza con interazione credibile della persona reale del brand"
            : $mainSubject;
        $shotThreeSubject = $needsDualSubjectLock
            ? "payoff finale con persona reale del brand e {$productLabel} ancora presenti e riconoscibili"
            : $mainSubject;

        return [
            'hook' => $hook,
            'anchor_frame' => $anchor,
            'continuity_lock' => $continuity,
            'visual_payoff' => $payoff,
            'shots' => [
                [
                    'order' => 1,
                    'purpose' => 'hook iniziale immediato',
                    'subject' => $mainSubject,
                    'camera' => 'wide o medium shot verticale leggibile',
                    'motion' => 'push-in leggero o reveal breve',
                ],
                [
                    'order' => 2,
                    'purpose' => trim((string) ($videoSegments['development_3_8'] ?? '')) !== ''
                        ? trim((string) $videoSegments['development_3_8'])
                        : ($angle !== '' ? "sviluppo dell angolo {$angle}" : 'sviluppo del contesto e del valore del contenuto'),
                    'subject' => $shotTwoSubject,
                    'camera' => 'angolazione diversa ma coerente',
                    'motion' => 'tracking morbido o micro parallax',
                ],
                [
                    'order' => 3,
                    'purpose' => trim((string) ($videoSegments['cta_ending'] ?? '')) !== ''
                        ? trim((string) $videoSegments['cta_ending'])
                        : ($objective !== '' ? "chiusura che spinge {$objective}" : 'payoff finale del reel'),
                    'subject' => $shotThreeSubject,
                    'camera' => 'close medium o dettaglio premium',
                    'motion' => 'movimento pulito e conclusivo',
                ],
            ],
            'source_prompt' => Str::limit($videoPrompt, 180, ''),
            'tone_hint' => $toneHint,
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>|null
     */
    public function compactReelBlueprintSummary(array $blueprint): ?array
    {
        if (empty($blueprint)) {
            return null;
        }

        $shots = collect((array) ($blueprint['shots'] ?? []))
            ->map(function ($shot) {
                if (!is_array($shot)) {
                    return null;
                }

                return [
                    'order' => (int) ($shot['order'] ?? 0),
                    'purpose' => Str::limit(trim((string) ($shot['purpose'] ?? '')), 70, ''),
                    'camera' => Str::limit(trim((string) ($shot['camera'] ?? '')), 60, ''),
                    'motion' => Str::limit(trim((string) ($shot['motion'] ?? '')), 60, ''),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'hook' => Str::limit(trim((string) ($blueprint['hook'] ?? '')), 90, ''),
            'anchor_frame' => Str::limit(trim((string) ($blueprint['anchor_frame'] ?? '')), 90, ''),
            'continuity_lock' => Str::limit(trim((string) ($blueprint['continuity_lock'] ?? '')), 120, ''),
            'visual_payoff' => Str::limit(trim((string) ($blueprint['visual_payoff'] ?? '')), 90, ''),
            'shot_count' => count($shots),
            'shots' => $shots,
        ];
    }

    /**
     * @param  array<string, mixed>  $storyboard
     */
    public function storyboardPromptInstruction(array $storyboard): string
    {
        $sceneSummary = $this->summarizeStoryboardSceneChunk((array) ($storyboard['scene_list'] ?? []));
        if ($sceneSummary === '') {
            return '';
        }

        $parts = [
            'Scene planner attivo: usa una progressione chiara per scene, non una clip indistinta.',
            'Storyboard: ' . $sceneSummary . '.',
            'Lascia spazio pulito per overlay temporizzati senza generare testo dentro il video.',
        ];

        if ((bool) ($storyboard['identity_first'] ?? false)) {
            $parts[] = 'Evita di coprire volto, prodotto o altri elementi identitari chiave nella zona focale centrale.';
        }

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 720, '');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     */
    public function buildRunwayReelExecutionPrompt(
        string $videoPrompt,
        ContentItem $item,
        array $meta,
        array $assetVariables
    ): string {
        $format = Str::lower(trim((string) ($item->format ?? 'post')));
        if ($format !== 'reel') {
            return $videoPrompt;
        }

        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $series = trim((string) data_get($meta, 'item_brain.series', data_get($meta, 'editorial.series', '')));
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $controlContext = $this->videoSubjectContextText($meta, (string) data_get($meta, 'manual_brief', ''), $videoPrompt);
        $needsDualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $controlContext, $assetVariables);
        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $controlContext));
        $productLabel = $productLabel !== '' ? $productLabel : 'il veicolo o prodotto richiesto nel brief';
        $blueprint = $this->normalizeReelBlueprint(
            blueprint: is_array(data_get($meta, 'reel_blueprint', [])) ? (array) data_get($meta, 'reel_blueprint', []) : [],
            item: $item,
            meta: $meta,
            assetVariables: $assetVariables,
            videoPrompt: $videoPrompt
        );

        $parts = [$videoPrompt];
        $parts[] = 'Runway execution mode: una sola clip coerente, guidata da un anchor frame forte e da una progressione breve.';
        $parts[] = 'Costruisci il contenuto come un vero reel social di 3-5 shot concatenati, non come una clip piatta o ripetitiva.';
        $parts[] = 'Mantieni un hook visivo entro il primo secondo, con soggetto chiaro e subito leggibile.';
        $parts[] = 'Deve sembrare un reel nativo da feed Instagram: stop-scroll, ritmo chiaro, soggetto forte, non una brochure animata.';
        $parts[] = 'Look live-action premium, fotorealistico, con pelle vera, riflessi reali su metallo e carrozzeria, ottiche credibili e movimento naturale.';
        if ($objective !== '') {
            $parts[] = "Obiettivo strategico da far percepire nel reel: {$objective}.";
        }
        if ($tone !== '') {
            $parts[] = "Tono del brand: {$tone}.";
        }
        if ($angle !== '') {
            $parts[] = "Angolo narrativo: {$angle}.";
        }
        if ($series !== '') {
            $parts[] = "Filone editoriale: {$series}.";
        }
        if ($hasPersonVariable) {
            $parts[] = 'Se compare la persona del brand, deve restare la stessa in tutti gli shot del reel.';
        }
        if ($needsDualSubjectLock) {
            $parts[] = "Vincolo visivo: la persona del brand e {$productLabel} devono restare entrambi presenti e riconoscibili.";
            $parts[] = "Non trasformare il reel in un portrait della sola persona: {$productLabel} deve essere ben visibile gia nel hook, nello sviluppo e nel payoff finale.";
            $parts[] = "Se il brief indica marca, modello o colore di {$productLabel}, rispettali senza reinterpretarli.";
        }
        if (!empty($blueprint)) {
            $parts[] = 'Hook: ' . (string) ($blueprint['hook'] ?? '');
            $parts[] = 'Anchor frame: ' . (string) ($blueprint['anchor_frame'] ?? '');
            $parts[] = 'Continuity lock: ' . (string) ($blueprint['continuity_lock'] ?? '');
            foreach ((array) ($blueprint['shots'] ?? []) as $shot) {
                if (!is_array($shot)) {
                    continue;
                }
                $parts[] = sprintf(
                    'Shot %d: %s. Soggetto: %s. Camera: %s. Movimento: %s.',
                    max(1, (int) ($shot['order'] ?? 1)),
                    trim((string) ($shot['purpose'] ?? '')),
                    trim((string) ($shot['subject'] ?? '')),
                    trim((string) ($shot['camera'] ?? '')),
                    trim((string) ($shot['motion'] ?? ''))
                );
            }
            $parts[] = 'Payoff finale: ' . (string) ($blueprint['visual_payoff'] ?? '');
        } else {
            $parts[] = 'Struttura consigliata: hook visivo entro il primo secondo, sviluppo con 2 o 3 scene leggibili, chiusura con payoff visivo pulito.';
        }
        $parts[] = 'Ogni shot deve cambiare davvero per angolazione, distanza, movimento camera o dettaglio principale, mantenendo continuita narrativa.';
        $parts[] = 'Movimenti camera preferiti: push-in leggero, reveal laterale, tracking morbido, micro parallax. Evita motion caotico.';
        $parts[] = $this->videoOutputLanguageInstruction($meta, (string) data_get($meta, 'manual_brief', ''));

        $limit = (int) (config('runway.max_prompt_chars') ?: 1400);
        $limit = max(600, min(1600, $limit));

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), $limit, '');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $activeFeedbackRequest
     */
    public function buildKlingExecutionPrompt(
        string $videoPrompt,
        ContentItem $item,
        array $meta,
        array $assetVariables,
        array $activeFeedbackRequest
    ): string {
        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $series = trim((string) data_get($meta, 'item_brain.series', data_get($meta, 'editorial.series', '')));
        $isReel = Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel';
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $controlContext = $this->videoSubjectContextText($meta, (string) data_get($meta, 'manual_brief', ''), $videoPrompt);
        $needsDualSubjectLock = $this->videoNeedsDualSubjectLock($meta, $controlContext, $assetVariables);
        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $controlContext));
        $productLabel = $productLabel !== '' ? $productLabel : 'il veicolo o prodotto richiesto nel brief';

        $parts = [$videoPrompt];
        if ($needsDualSubjectLock) {
            $parts[] = 'Kling execution rule: the references describe one coherent scene with two fixed anchors, the brand person and the requested product or vehicle.';
            $parts[] = "Keep both anchors stable across the full video: same face, same age perception, same hair and same body proportions for the person; same model, same color and same proportions for {$productLabel}.";
            $parts[] = "Do not let the person replace {$productLabel} and do not let {$productLabel} disappear after the first shot.";
        } else {
            $parts[] = 'Kling execution rule: if multiple reference images are present, they describe the same real subject from different angles, not different people.';
            $parts[] = 'Keep one single subject identity across the full video: same face, same age perception, same hair, same body proportions, same overall presence.';
        }
        $parts[] = 'Change scene, camera, gesture, styling and lighting only when useful, but do not drift identity.';
        $parts[] = 'No duplicate subject, no face swap, no identity drift, no extra people replacing the brand subject.';
        $parts[] = 'Live-action photorealism only: real skin texture, real lens behavior, realistic reflections on metal and paint, natural motion, no stylized rendering.';

        if ($isReel) {
            $parts[] = 'Build a vertical 9:16 social reel with 3 to 5 clear shots, a fast hook in the first second and a clean visual payoff at the end.';
            $parts[] = 'Treat it like a native Instagram reel: quick readability, clear camera intention, premium pacing, no static brochure feel.';
        }

        if ($objective !== '') {
            $parts[] = "Strategic objective: {$objective}.";
        }
        if ($tone !== '') {
            $parts[] = "Brand tone: {$tone}.";
        }
        if ($angle !== '') {
            $parts[] = "Narrative angle: {$angle}.";
        }
        if ($series !== '') {
            $parts[] = "Editorial series: {$series}.";
        }
        if ($hasPersonVariable) {
            $parts[] = 'Use the persona pack as an identity board first, and only secondarily as style guidance.';
        }
        if ($needsDualSubjectLock) {
            $parts[] = "The person and {$productLabel} must both be visible in the opening hook and return again in the final payoff shot.";
            $parts[] = "If the brief specifies brand, model or color for {$productLabel}, preserve them exactly.";
        }
        if ($this->feedbackTargetsVisual($activeFeedbackRequest)) {
            $parts[] = 'This generation follows a correction request: the new result must visibly improve, not just slightly vary the previous cut.';
        }
        $parts[] = $this->videoOutputLanguageInstruction($meta, (string) data_get($meta, 'manual_brief', ''), true);

        $limit = (int) (config('kling.max_prompt_chars') ?: 1400);
        $limit = max(400, min(1800, $limit));

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), $limit, '');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     */
    public function buildGoogleVeoExecutionPrompt(
        string $videoPrompt,
        ContentItem $item,
        array $meta,
        array $assetVariables
    ): string {
        $objective = trim((string) data_get($meta, 'item_brain.objective', data_get($meta, 'plan.goal', '')));
        $tone = trim((string) data_get($meta, 'strategy.brand_voice.tone', data_get($meta, 'plan.tone', '')));
        $angle = trim((string) data_get($meta, 'item_brain.angle', data_get($meta, 'editorial.angle', '')));
        $series = trim((string) data_get($meta, 'item_brain.series', data_get($meta, 'editorial.series', '')));
        $isReel = Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel';
        $blueprint = is_array(data_get($meta, 'reel_blueprint', []))
            ? (array) data_get($meta, 'reel_blueprint', [])
            : [];
        $storyboard = is_array(data_get($meta, 'storyboard_meta', []))
            ? (array) data_get($meta, 'storyboard_meta', [])
            : [];

        $parts = [
            'Create a live-action photorealistic social video with stable subject identity, natural motion and clean premium framing.',
            'No on-screen text, no watermark, no fake logos, no extra subjects replacing the main subject.',
            'Use natural lens behavior, realistic light, believable skin texture and real-world reflections.',
        ];

        if ($isReel) {
            $parts[] = 'Format target: native Instagram reel, vertical 9:16, immediate hook in the first second, then a clear visual development and payoff.';
        }
        if ($objective !== '') {
            $parts[] = "Strategic objective: {$objective}.";
        }
        if ($tone !== '') {
            $parts[] = "Brand tone: {$tone}.";
        }
        if ($angle !== '') {
            $parts[] = "Narrative angle: {$angle}.";
        }
        if ($series !== '') {
            $parts[] = "Editorial series: {$series}.";
        }
        if (!empty($blueprint['hook'])) {
            $parts[] = 'Opening hook to preserve: ' . trim((string) $blueprint['hook']) . '.';
        }
        if (!empty($blueprint['continuity_lock'])) {
            $parts[] = 'Continuity lock: ' . trim((string) $blueprint['continuity_lock']) . '.';
        }
        $sceneSummary = $this->summarizeStoryboardSceneChunk((array) data_get($storyboard, 'scene_list', []));
        if ($sceneSummary !== '') {
            $parts[] = "Scene plan: {$sceneSummary}.";
        }
        if ($this->hasPersonAssetVariable($assetVariables)) {
            $parts[] = 'If the brand person appears, keep the same real person identity from start to end.';
        }
        $parts[] = $this->videoOutputLanguageInstruction($meta, (string) data_get($meta, 'manual_brief', ''), true);

        $parts[] = 'Primary visual brief follows. Proper names and brand specifics may appear in Italian; preserve them faithfully.';
        $parts[] = $videoPrompt;

        $limit = (int) (config('google_veo.max_prompt_chars') ?: 1600);
        $limit = max(600, min(2000, $limit));

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), $limit, '');
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @return array<int, string>
     */
    public function videoLocationSequenceNames(array $assetVariables): array
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        $names = [];
        foreach ($resolved as $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
            if ($kind !== 'location') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique(array_slice($names, 0, 4)));
    }

    public function resolveVideoProvider(array $meta): string
    {
        $preferred = trim((string) data_get($meta, 'video_provider', ''));

        return $this->capabilityRegistry()->resolveConfiguredProvider(
            'video',
            $preferred,
            VideoProviderResolver::default()
        );
    }

    public function shouldAllowCrossProviderVideoFallback(array $meta): bool
    {
        if ((bool) data_get($meta, 'video_provider_lock', false)) {
            return false;
        }

        $provider = $this->resolveVideoProvider($meta);

        return !$this->isStrictVideoModelSelection($provider, $meta);
    }

    public function canAttemptSecondaryVideoFallback(array $meta, string $provider): bool
    {
        // Explicit no-fallback flag: provider failure is surfaced as error, no silent degradation.
        if ((bool) data_get($meta, 'video_no_fallback', false)) {
            return false;
        }

        if ($this->shouldAllowCrossProviderVideoFallback($meta)) {
            return true;
        }

        return $this->shouldAllowLockedVideoProviderFailover($meta, $provider);
    }

    public function shouldAllowLockedVideoProviderFailover(array $meta, string $provider): bool
    {
        if (!(bool) config('generation.locked_video_provider_failover', true)) {
            return false;
        }

        if (!(bool) data_get($meta, 'video_provider_lock', false)) {
            return false;
        }

        return $this->resolveVideoProvider($meta) === strtolower(trim($provider));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $referencePaths
     */
    public function resolveRunwayVideoModel(ContentItem $item, array $meta, array $assetVariables, array $referencePaths): string
    {
        $explicit = trim((string) data_get($meta, 'video_model', ''));
        if ($explicit !== '') {
            return $this->normalizeRunwayVideoModel($explicit);
        }

        $configured = $this->normalizeRunwayVideoModel($this->capabilityRegistry()->defaultModel('runway', 'video'));
        if ($this->isStrictVideoModelSelection('runway', $meta)) {
            return $configured;
        }

        $format = strtolower(trim((string) ($item->format ?? 'post')));
        $hasReferences = !empty(array_filter($referencePaths, fn ($path) => is_string($path) && trim($path) !== ''));
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);

        if (str_starts_with($configured, 'veo3') && ($hasPersonVariable || $hasReferences)) {
            return 'gen4.5';
        }

        if ($hasPersonVariable || $hasReferences || $format === 'reel') {
            return $configured;
        }

        return $configured;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function resolveGoogleVeoVideoModel(array $meta): string
    {
        $explicit = trim((string) data_get($meta, 'video_model', ''));
        if ($explicit !== '') {
            return $this->normalizeGoogleVeoVideoModel($explicit);
        }

        return $this->normalizeGoogleVeoVideoModel($this->capabilityRegistry()->defaultModel('google_veo', 'video'));
    }

    /**
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    public function normalizeVideoOptionsForProvider(string $provider, array $videoOptions): array
    {
        $provider = strtolower(trim($provider));
        $size = trim((string) ($videoOptions['size'] ?? ''));
        if ($size === '') {
            $size = (string) (config('openai.video_size') ?: '720x1280');
        }

        $model = $this->normalizeVideoModelForProvider(
            $provider,
            trim((string) ($videoOptions['model'] ?? ''))
        );

        $seconds = (int) ($videoOptions['seconds'] ?? 0);
        if ($seconds <= 0) {
            $seconds = match ($provider) {
                'runway' => (int) (config('runway.video_seconds') ?: 8),
                'google_veo' => (int) (config('google_veo.video_seconds') ?: 8),
                'kling' => (int) (config('kling.video_seconds') ?: 5),
                default => (int) (config('openai.video_seconds') ?: 8),
            };
        }
        $seconds = $this->normalizeVideoSecondsForProvider($provider, $seconds, $model);

        $videoOptions['model'] = $model;
        $videoOptions['seconds'] = $seconds;
        $videoOptions['size'] = $size;

        return $videoOptions;
    }

    public function normalizeVideoSecondsForProvider(string $provider, int $seconds, string $model = ''): int
    {
        return $this->capabilityRegistry()->normalizeVideoDuration($provider, $seconds, $model);
    }

    public function isRunwayVeoModel(string $model): bool
    {
        $normalized = $this->normalizeRunwayVideoModel($model);

        return str_starts_with($normalized, 'veo3');
    }

    public function normalizeOpenAiVideoModel(string $model): string
    {
        return $this->capabilityRegistry()->normalizeModel('openai', 'video', $model);
    }

    public function normalizeRunwayVideoModel(string $model): string
    {
        return $this->capabilityRegistry()->normalizeModel('runway', 'video', $model);
    }

    public function normalizeKlingVideoModel(string $model): string
    {
        return $this->capabilityRegistry()->normalizeModel('kling', 'video', $model);
    }

    public function normalizeGoogleVeoVideoModel(string $model): string
    {
        return $this->capabilityRegistry()->normalizeModel('google_veo', 'video', $model);
    }

    public function normalizeVideoModelForProvider(string $provider, string $model = '', array $context = []): string
    {
        return match (strtolower(trim($provider))) {
            'runway' => $this->capabilityRegistry()->normalizeModel('runway', 'video', $model, $context),
            'google_veo' => $this->capabilityRegistry()->normalizeModel('google_veo', 'video', $model, $context),
            'kling' => $this->capabilityRegistry()->normalizeModel('kling', 'video', $model, $context),
            default => $this->capabilityRegistry()->normalizeModel('openai', 'video', $model, $context),
        };
    }

    public function resolveImageProvider(array $meta): string
    {
        $source = trim((string) data_get($meta, 'source', ''));
        $mode = trim((string) data_get($meta, 'plan.mode', ''));

        $allowsProviderOverride = in_array($source, ['manual_single_content', 'onboarding_quickstart_demo'], true)
            || $mode === 'single_manual'
            || $mode === 'onboarding_quickstart_demo';

        if (!$allowsProviderOverride) {
            return $this->capabilityRegistry()->defaultProvider('image');
        }

        return $this->capabilityRegistry()->resolveProvider(
            'image',
            (string) data_get($meta, 'image_provider', ''),
            ImageProviderResolver::default()
        );
    }

    public function capabilityRegistry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }

    public function generateImageTextWithProvider(
        string $provider,
        string $prompt,
        OpenAiService $openAi,
        NanoBananaService $nanoBanana,
        bool $allowFallback = true
    ): array {
        if ($provider === 'openai') {
            return $openAi->generateImageBase64($prompt);
        }

        try {
            return $nanoBanana->generateImageBase64($prompt);
        } catch (Throwable $e) {
            if (!$allowFallback || !$this->shouldFallbackFromNanoBananaToOpenAi($e)) {
                throw $e;
            }

            Log::warning('GenerateAiForContentItem image generation falling back from NanoBanana to OpenAI', [
                'content_item_id' => $this->contentItemId,
                'error' => Str::limit($e->getMessage(), 220, ''),
            ]);

            return $openAi->generateImageBase64($prompt);
        }
    }

    /**
     * @param  array<int, string>  $editPaths
     */
    public function generateImageEditWithProvider(
        string $provider,
        string $prompt,
        array $editPaths,
        OpenAiService $openAi,
        NanoBananaService $nanoBanana,
        bool $allowFallback = true
    ): array {
        if ($provider === 'openai') {
            return $openAi->generateImageEditBase64($prompt, $editPaths);
        }

        try {
            return $nanoBanana->generateImageEditBase64($prompt, $editPaths);
        } catch (Throwable $e) {
            if (!$allowFallback || !$this->shouldFallbackFromNanoBananaToOpenAi($e)) {
                throw $e;
            }

            Log::warning('GenerateAiForContentItem image edit falling back from NanoBanana to OpenAI', [
                'content_item_id' => $this->contentItemId,
                'error' => Str::limit($e->getMessage(), 220, ''),
                'edit_paths_count' => count($editPaths),
            ]);

            return $openAi->generateImageEditBase64($prompt, $editPaths);
        }
    }

    /**
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    public function augmentPromptForInstagramImageExecution(
        ContentItem $item,
        string $prompt,
        array $selectedBrandImagePaths,
        array $assetVariables,
        array $activeFeedbackRequest = []
    ): string {
        $itemBrain = is_array(data_get($item->ai_meta, 'item_brain', []))
            ? data_get($item->ai_meta, 'item_brain', [])
            : [];
        $forcePhotorealism = ImagePromptRealismGuard::shouldForcePhotorealism(
            (string) data_get($item->ai_meta, 'manual_brief', ''),
            $prompt
        );
        $locationEnvelopeProtected = $this->hasProtectedLocationEnvelope($assetVariables, $selectedBrandImagePaths);
        $hasExplicitHumanReferences = $this->hasExplicitHumanReferences($assetVariables);
        $prompt = ImagePromptRealismGuard::sanitize($prompt, $forcePhotorealism);

        $parts = [
            trim($prompt),
            $this->instagramVisualOutputInstruction($item),
            $this->socialGraphicSystemInstruction($item, $itemBrain),
            $this->multiReferenceBlendInstruction($selectedBrandImagePaths),
            $this->locationEnvelopePreservationInstruction($assetVariables, $selectedBrandImagePaths),
            $this->feedbackDrivenImageInstruction($activeFeedbackRequest, $selectedBrandImagePaths, $assetVariables),
            ImagePromptRealismGuard::instruction($forcePhotorealism, $locationEnvelopeProtected, $hasExplicitHumanReferences),
            'Il risultato deve sembrare un contenuto editoriale premium pensato per Instagram, non una demo tecnica.',
            'PRIORITA MASSIMA — REGOLA FINALE: ZERO testo nell\'immagine. Nessuna lettera, parola, numero, scritta, titolo, watermark, overlay tipografico o logo renderizzato. Solo elementi visivi puri.',
        ];

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    public function instagramVisualOutputInstruction(ContentItem $item): string
    {
        return 'Output finale verticale 4:5, pronto per Instagram feed, con composizione premium, focus principale netto, margini puliti e gerarchia visiva forte. Questo deve sembrare un post social studiato per fermare lo scroll, non una foto corporate generica: soggetto chiaro subito, lettura forte anche da miniatura e resa nativa da feed.';
    }

    /**
     * @param  array<string, mixed>  $itemBrain
     */
    public function socialGraphicSystemInstruction(ContentItem $item, array $itemBrain): string
    {
        $position = $this->positionInPlan($item) + 1;
        $total = max(1, $this->totalItemsInPlan($item));
        $seriesName = trim((string) data_get($itemBrain, 'series_name', ''));
        $connectionHint = trim((string) data_get($itemBrain, 'connection_hint', ''));
        $overlayBrief = trim((string) data_get($itemBrain, 'overlay_brief', ''));
        $trendBridge = trim((string) data_get($itemBrain, 'trend_bridge', ''));

        $parts = [
            "Questo contenuto fa parte di un piano di pubblicazioni social: posizione {$position} di {$total}.",
            'Il visual deve funzionare come post social vero: stop-scroll, impatto immediato, gerarchia visiva chiara, niente look da brochure o catalogo statico.',
            'Mantieni coerenza con il feed del brand, ma rendi il contenuto visivamente distinto dai post vicini con un angolo e una composizione pensati per i social.',
        ];

        if ($seriesName !== '') {
            $parts[] = "Serie o filone: {$seriesName}.";
        }

        if ($connectionHint !== '') {
            $parts[] = "Ruolo nel piano: {$connectionHint}";
        }
        // overlayBrief NON viene incluso nel prompt immagine: chiedere "predisponi layout overlay"
        // confonde i modelli image-gen (Gemini/DALL-E) che tendono a renderizzare testo nell'immagine.
        // Il brief overlay è usato solo nel contesto testo/video dove ha senso semantico.
        if ($trendBridge !== '') {
            $parts[] = "Se usi meccaniche trend, applicale cosi: {$trendBridge}.";
        }

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $itemBrain
     */
    public function creativeDirectionPromptInstruction(array $strategy, array $itemBrain): string
    {
        $creativeDirection = (array) data_get($strategy, 'creative_direction', []);
        $parts = [];

        $qualityBar = trim((string) data_get($creativeDirection, 'professional_direction.quality_bar', ''));
        if ($qualityBar !== '') {
            $parts[] = $qualityBar;
        }

        $viralHookStyle = trim((string) data_get($itemBrain, 'viral_hook_style', ''));
        if ($viralHookStyle !== '') {
            $parts[] = 'Hook social: ' . $viralHookStyle;
        }

        $shareabilityDriver = trim((string) data_get($itemBrain, 'shareability_driver', ''));
        if ($shareabilityDriver !== '') {
            $parts[] = 'Shareability: ' . $shareabilityDriver;
        }

        $trendBridge = trim((string) data_get($itemBrain, 'trend_bridge', ''));
        if ($trendBridge !== '') {
            $parts[] = 'Trend brand-safe: ' . $trendBridge;
        }

        $trendGuardrails = collect((array) data_get($itemBrain, 'trend_guardrails', data_get($creativeDirection, 'trend_policy.disallowed_mechanics', [])))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->take(4)
            ->values()
            ->all();
        if (!empty($trendGuardrails)) {
            $parts[] = 'Evita: ' . implode(', ', $trendGuardrails);
        }

        $overlayBrief = trim((string) data_get($itemBrain, 'overlay_brief', ''));
        if ($overlayBrief !== '') {
            $parts[] = 'Composizione overlay-ready: ' . $overlayBrief;
        }

        $continuityBrief = trim((string) data_get($itemBrain, 'continuity_brief', ''));
        if ($continuityBrief !== '') {
            $parts[] = 'Continuita identitaria: ' . $continuityBrief;
        }

        return Str::limit(implode(' ', array_filter($parts)), 780, '');
    }

    /**
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    public function multiReferenceBlendInstruction(array $selectedBrandImagePaths): string
    {
        $paths = array_values(array_filter($selectedBrandImagePaths, fn ($path) => is_string($path) && $path !== ''));

        if (count($paths) < 2) {
            return 'Se usi un solo riferimento, mantieni il DNA visivo reale ma migliora resa, inquadratura e impatto social.';
        }

        return 'Se usi piu immagini di riferimento, fondile in un unica scena coerente e credibile: niente collage, niente split-screen, niente griglia, niente foto appoggiate una sopra l altra. Unifica luce, prospettiva, palette, styling e contesto narrativo come se fosse un solo shooting strategico.';
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<int, string>  $selectedBrandImagePaths
     * @param  array<string, mixed>  $assetVariables
     * @return array<int, string>
     */
    public function stabilizeReferencePathsForFeedback(
        array $selectedBrandImagePaths,
        array $feedbackRequest,
        array $assetVariables
    ): array {
        $paths = array_values(array_filter($selectedBrandImagePaths, fn ($path) => is_string($path) && trim($path) !== ''));

        if (count($paths) < 2) {
            return $paths;
        }

        if (!$this->feedbackForcesPrimaryLocationAnchor($feedbackRequest, $assetVariables, $paths)) {
            return $paths;
        }

        return [$paths[0]];
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<int, string>  $selectedBrandImagePaths
     * @param  array<string, mixed>  $assetVariables
     */
    public function feedbackDrivenImageInstruction(
        array $feedbackRequest,
        array $selectedBrandImagePaths,
        array $assetVariables
    ): string {
        if (!$this->feedbackTargetsVisual($feedbackRequest)) {
            return '';
        }

        $category = Str::lower(trim((string) ($feedbackRequest['normalized_category'] ?? $feedbackRequest['category'] ?? '')));
        $reason = trim((string) ($feedbackRequest['reason'] ?? ''));
        $parts = [];

        if ($reason !== '') {
            $parts[] = 'Correzione prioritaria utente da rispettare davvero: ' . $reason . '.';
        }

        if ($this->feedbackForcesPrimaryLocationAnchor($feedbackRequest, $assetVariables, $selectedBrandImagePaths)) {
            $parts[] = 'Usa la prima immagine reale come ancora strutturale obbligatoria del luogo.';
            $parts[] = 'Non inventare nuove sale, muri, finestre, aperture, prospettive o layout diversi.';
            $parts[] = 'Se esistono altri riferimenti, servono solo per dettagli secondari coerenti, non per fondere ambienti diversi.';
        }

        if (in_array($category, ['realism', 'person_not_consistent'], true)) {
            $parts[] = 'Se aggiungi persone, evita close-up inventati e preferisci figure credibili in media distanza, con volti, mani, postura e presenza naturali.';
            $parts[] = 'La persona del brand deve restare riconoscibile: stessi lineamenti, stesso volto e stessa identita dei riferimenti.';
        }

        if (in_array($category, ['visual_composition', 'low_quality_visual'], true)) {
            $parts[] = 'Cambia davvero composizione, inquadratura e gerarchia visiva, mantenendo pero il luogo autentico se e reale.';
            $parts[] = 'Migliora nitidezza, luce, proporzioni e credibilita generale della scena.';
        }

        if ($category === 'product_deformed') {
            $parts[] = 'Se compare un prodotto reale, mantieni forma, packaging, etichetta e proporzioni coerenti senza deformazioni o reinterpretazioni.';
        }

        if ($category === 'off_brand') {
            $parts[] = 'Riallinea palette, styling, atmosfera, luce e dress code al posizionamento reale del brand.';
        }

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<string, mixed>  $assetVariables
     */
    public function feedbackDrivenVideoInstruction(
        array $feedbackRequest,
        array $assetVariables,
        bool $locationSequenceMode = false
    ): string {
        if (!$this->feedbackTargetsVisual($feedbackRequest)) {
            return '';
        }

        $category = Str::lower(trim((string) ($feedbackRequest['normalized_category'] ?? $feedbackRequest['category'] ?? '')));
        $reason = trim((string) ($feedbackRequest['reason'] ?? ''));
        $reasonNormalized = $this->normalizeText($reason);
        $parts = [];

        if ($reason !== '') {
            $parts[] = 'Correzione video prioritaria da rispettare davvero: ' . $reason . '.';
        }

        $parts[] = 'Questa e una rigenerazione dopo feedback negativo: il nuovo video deve cambiare in modo evidente rispetto alla versione precedente, non basta una micro-variazione.';
        $parts[] = 'Cambia davvero apertura, sequenza delle scene, regia, camera movement, ritmo o styling mantenendo obiettivo e strategia.';

        if ($this->hasPersonAssetVariable($assetVariables)) {
            $parts[] = 'Se c e una persona di riferimento del brand, la sua identita deve restare la stessa tra una versione e l altra: stesso volto, stessi lineamenti, stessa eta apparente e stessa presenza.';
        }

        if ($this->feedbackDemandsPersonaIdentityLock($reasonNormalized, $category)) {
            $parts[] = 'La persona deve sembrare davvero quella dei riferimenti: usa volto, lineamenti, proporzioni, capelli e presenza come ancora rigida.';
        }

        if ($locationSequenceMode || in_array($category, ['location_integrity', 'location_not_consistent'], true)) {
            $parts[] = 'Mantieni i luoghi reali autentici e separati se sono ambienti diversi, senza fonderli o inventare spazi nuovi.';
        }

        if (in_array($category, ['realism', 'person_not_consistent', 'low_quality_visual'], true)) {
            $parts[] = 'Volti, mani, postura, sguardo, texture e movimenti devono risultare naturali e credibili, senza uncanny effect.';
        }

        if (in_array($category, ['visual_composition', 'low_quality_visual'], true)) {
            $parts[] = 'Cambia in modo netto lo shot plan: inquadratura iniziale, ordine dei frame, distanze camera e gerarchia visiva delle scene.';
        }

        if ($category === 'product_deformed') {
            $parts[] = 'Se il video mostra un prodotto reale, mantieni forma, proporzioni, packaging e dettagli senza deformazioni tra uno shot e l altro.';
        }

        if (in_array($category, ['brand_alignment', 'off_brand'], true)) {
            $parts[] = 'Riallinea outfit, atmosfera, ambiente, luce e comportamento del soggetto al posizionamento reale del brand.';
        }

        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    public function feedbackForcesPrimaryLocationAnchor(
        array $feedbackRequest,
        array $assetVariables,
        array $selectedBrandImagePaths
    ): bool {
        $category = Str::lower(trim((string) ($feedbackRequest['normalized_category'] ?? $feedbackRequest['category'] ?? '')));
        $reason = $this->normalizeText((string) ($feedbackRequest['reason'] ?? ''));

        if (in_array($category, ['location_integrity', 'location_not_consistent'], true)) {
            return true;
        }

        if (!$this->hasProtectedLocationEnvelope($assetVariables, $selectedBrandImagePaths)) {
            return false;
        }

        foreach ([
            'sala inventata',
            'altra sala',
            'ambiente diverso',
            'non cambiare il locale',
            'deve rimanere com e',
            'deve restare com e',
            'non inventare',
        ] as $needle) {
            if ($reason !== '' && str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function feedbackDemandsPersonaIdentityLock(string $reasonNormalized, string $category = ''): bool
    {
        if (Str::lower(trim($category)) === 'person_not_consistent') {
            return true;
        }

        if ($reasonNormalized === '') {
            return false;
        }

        foreach ([
            'non sembra lei',
            'non e lei',
            'non ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨ lei',
            'volto diverso',
            'viso diverso',
            'faccia diversa',
            'non sembra la persona',
            'non riconoscibile',
        ] as $needle) {
            if (str_contains($reasonNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     */
    public function feedbackTargetsVisual(array $feedbackRequest): bool
    {
        if (empty($feedbackRequest)) {
            return false;
        }

        $scope = Str::lower(trim((string) ($feedbackRequest['scope'] ?? '')));
        $category = Str::lower(trim((string) ($feedbackRequest['normalized_category'] ?? $feedbackRequest['category'] ?? '')));
        $visualCategories = [
            'realism',
            'visual_composition',
            'location_integrity',
            'brand_alignment',
            'person_not_consistent',
            'location_not_consistent',
            'product_deformed',
            'low_quality_visual',
            'off_brand',
            'not_publishable',
        ];
        $copyOnlyCategories = [
            'too_generic',
            'too_salesy',
            'wrong_cta',
            'audio_unatural',
            'caption_copy',
            'call_to_action',
            'tone_of_voice',
            'offer_focus',
        ];

        if ($scope === 'visual_first') {
            return true;
        }

        if (in_array($category, $visualCategories, true)) {
            return true;
        }

        if ($scope === 'full' && !in_array($category, $copyOnlyCategories, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $feedbackRequest
     * @return array<string, mixed>
     */
    public function normalizeFeedbackRequest(array $feedbackRequest): array
    {
        if (empty($feedbackRequest)) {
            return [];
        }

        $category = Str::lower(trim((string) ($feedbackRequest['category'] ?? '')));
        $scope = Str::lower(trim((string) ($feedbackRequest['scope'] ?? 'full')));
        $reason = trim((string) ($feedbackRequest['reason'] ?? ''));
        $sentiment = trim((string) ($feedbackRequest['sentiment'] ?? ''));
        $action = trim((string) ($feedbackRequest['action'] ?? ''));
        $normalizedCategory = Str::lower(trim((string) ($feedbackRequest['normalized_category'] ?? '')));

        if ($normalizedCategory === '') {
            $normalizedCategory = \App\Models\ContentFeedbackEntry::normalizeCategory($category, $reason, $scope);
        }

        $severity = Str::lower(trim((string) ($feedbackRequest['severity'] ?? '')));
        if ($severity === '') {
            $severity = \App\Models\ContentFeedbackEntry::resolveSeverity(null, $normalizedCategory, $reason, $action, $sentiment);
        }

        $scores = collect((array) ($feedbackRequest['scores'] ?? []))
            ->mapWithKeys(function ($value, $key): array {
                if (!is_numeric($value)) {
                    return [];
                }

                return [(string) $key => $value + 0];
            })
            ->all();

        return [
            'feedback_id' => isset($feedbackRequest['feedback_id']) ? (int) $feedbackRequest['feedback_id'] : null,
            'sentiment' => $sentiment,
            'category' => $category,
            'category_label' => trim((string) ($feedbackRequest['category_label'] ?? '')),
            'normalized_category' => $normalizedCategory,
            'normalized_category_label' => trim((string) ($feedbackRequest['normalized_category_label'] ?? \App\Models\ContentFeedbackEntry::labelForCategory($normalizedCategory))),
            'scope' => $scope,
            'severity' => $severity,
            'severity_label' => trim((string) ($feedbackRequest['severity_label'] ?? \App\Models\ContentFeedbackEntry::labelForSeverity($severity))),
            'reason' => $reason,
            'action' => $action,
            'instruction' => trim((string) ($feedbackRequest['instruction'] ?? '')),
            'scores' => $scores,
            'created_at' => trim((string) ($feedbackRequest['created_at'] ?? '')),
            'requested_at' => trim((string) ($feedbackRequest['requested_at'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    public function locationEnvelopePreservationInstruction(array $assetVariables, array $selectedBrandImagePaths): string
    {
        if (!$this->hasProtectedLocationEnvelope($assetVariables, $selectedBrandImagePaths)) {
            return '';
        }

        return 'Se tra i riferimenti c e un luogo reale come ufficio, edificio, showroom o punto vendita, quello resta l involucro principale da preservare: mantieni architettura, layout, prospettiva e dettagli distintivi del posto. Puoi aggiungere persone, prodotti, allestimenti, decorazioni e atmosfera coerenti con il brief, ma non trasformare quel luogo in un ambiente diverso.';
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $selectedBrandImagePaths
     */
    public function hasProtectedLocationEnvelope(array $assetVariables, array $selectedBrandImagePaths): bool
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        if (empty($resolved)) {
            return false;
        }

        $selectedLookup = array_fill_keys(
            array_values(array_filter($selectedBrandImagePaths, fn ($path) => is_string($path) && $path !== '')),
            true
        );
        $matchAnyLocation = empty($selectedLookup);

        foreach ($resolved as $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
            $text = Str::lower(trim((string) ($row['name'] ?? '') . ' ' . (string) ($row['description'] ?? '')));
            $matchesLocation = $kind === 'location'
                || str_contains($text, 'ufficio')
                || str_contains($text, 'edificio')
                || str_contains($text, 'showroom')
                || str_contains($text, 'negozio')
                || str_contains($text, 'locale')
                || str_contains($text, 'ristorante');

            if (!$matchesLocation) {
                continue;
            }

            $paths = array_values(array_filter(
                (array) ($row['asset_paths'] ?? []),
                fn ($path) => is_string($path) && trim($path) !== ''
            ));

            if ($matchAnyLocation || empty($paths)) {
                return true;
            }

            foreach ($paths as $path) {
                if (isset($selectedLookup[$path])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    public function hasExplicitHumanReferences(array $assetVariables): bool
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        if (empty($resolved)) {
            return false;
        }

        foreach ($resolved as $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
            $text = Str::lower(trim((string) ($row['name'] ?? '') . ' ' . (string) ($row['description'] ?? '')));

            if ($kind === 'person') {
                return true;
            }

            if (
                str_contains($text, 'persona')
                || str_contains($text, 'staff')
                || str_contains($text, 'team')
                || str_contains($text, 'chef')
                || str_contains($text, 'volto')
                || str_contains($text, 'dipendente')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    public function generateVideoWithGoogleVeo(
        GoogleVeoService $googleVeo,
        ContentItem $item,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referencePaths,
        string $referenceReason,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions,
        array $generationReferenceAbsPool,
        array $imageReferencePathPool
    ): array {
        $videoOptions = $this->normalizeVideoOptionsForProvider('google_veo', $videoOptions);
        // Reference images disponibili ma NON passate all'API: Veo image-to-video
        // le userebbe come primo frame, creando un video che inizia con la foto
        // brand ferma invece del video generato. Usiamo sempre text-to-video.
        $activeReferencePath = trim((string) $referencePath);
        if ($activeReferencePath === '' && !empty($imageReferencePathPool)) {
            $activeReferencePath = trim((string) ($imageReferencePathPool[0] ?? ''));
        }
        $activeReferencePaths = array_values(array_filter(
            $activeReferencePath !== '' ? [$activeReferencePath] : array_slice($referencePaths, 0, 1),
            fn ($value) => is_string($value) && $value !== ''
        ));
        $requestSummary = [
            'requested_reference_count' => count($imageReferencePathPool),
            'active_reference_count'    => 0,
            'image_input_skipped'       => true,
            'image_input_skip_reason'   => 'veo_image_to_video_forces_first_frame',
            'ignored_additional_references' => max(0, count($imageReferencePathPool)),
            'reference_reason' => $referenceReason,
        ];

        $googleVeoOptions = [
            'model' => (string) ($videoOptions['model'] ?? config('google_veo.model') ?: 'veo-3.1-generate-preview'),
            'seconds' => (int) ($videoOptions['seconds'] ?? (int) (config('google_veo.video_seconds') ?: 8)),
            'size' => (string) ($videoOptions['size'] ?? '720x1280'),
            'strict_model' => $this->isStrictVideoModelSelection('google_veo', is_array($item->ai_meta) ? $item->ai_meta : []),
            'negative_prompt' => trim((string) (config('google_veo.negative_prompt') ?: '')),
            'generate_audio' => false,
        ];

        // Google Veo image-to-video usa il reference come PRIMO FRAME del video
        // (comportamento API nativo). Per i contenuti brand le reference images
        // servono per l'identità, non come ancora del primo frame. Si usa sempre
        // text-to-video: il prompt contiene già la descrizione visiva del soggetto.
        $job = $googleVeo->createVideoJob(
            prompt: $videoPrompt,
            inputReferenceAbsolutePath: null,
            options: $googleVeoOptions
        );
        $videoId = (string) ($job['id'] ?? '');
        $jobFinal = $googleVeo->waitForVideoCompletion($videoId);
        $videoBytes = $googleVeo->downloadVideoContent($jobFinal);
        $thumbBytes = $googleVeo->downloadThumbnailContent($jobFinal);

        $videoExt = $this->detectVideoExtensionFromBytes($videoBytes);
        $videoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $videoExt;
        Storage::disk('public')->put($videoPath, $videoBytes);

        $thumbPath = null;
        if (is_string($thumbBytes) && $thumbBytes !== '') {
            $thumbExt = $this->detectImageExtensionFromBytes($thumbBytes);
            $thumbPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $thumbExt;
            Storage::disk('public')->put($thumbPath, $thumbBytes);
        }

        return [
            'source' => 'google_veo_video_generation',
            'provider' => 'google_veo',
            'video_id' => $videoId,
            'video_path' => $videoPath,
            'thumbnail_path' => $thumbPath,
            'reference_path' => $activeReferencePath,
            'reference_paths' => $activeReferencePaths,
            'reference_reason' => $activeReferenceAbs !== '' ? $referenceReason : $referenceReason . '_text_only',
            'reference_validation' => null,
            'composition_reference' => $compositionMeta,
            'generation_attempts' => 1,
            'job_status' => (bool) ($jobFinal['done'] ?? false) ? 'completed' : 'processing',
            'brand_selection' => $brandDecision,
            'provider_fallback' => null,
            'request_summary' => ($job['request_summary'] ?? []) + [
                'reel_blueprint' => $this->compactReelBlueprintSummary(
                    is_array(data_get($item->ai_meta, 'reel_blueprint', [])) ? (array) data_get($item->ai_meta, 'reel_blueprint', []) : []
                ),
                'storyboard' => $this->compactStoryboardSummary(
                    is_array(data_get($item->ai_meta, 'storyboard_meta', [])) ? (array) data_get($item->ai_meta, 'storyboard_meta', []) : []
                ),
            ],
            'reference_input_summary' => $requestSummary,
        ];
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    public function generateVideoWithKling(
        KlingService $kling,
        ContentItem $item,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referenceAbsPool,
        array $referencePaths,
        string $referenceReason,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $locationSequenceMode
    ): array {
        $videoOptions = $this->normalizeVideoOptionsForProvider('kling', $videoOptions);
        $preferredReferenceAbs = trim((string) $referenceAbs);
        $preferredReferencePath = trim((string) $referencePath);
        $preferredReferencePaths = $preferredReferencePath !== ''
            ? [$preferredReferencePath]
            : array_values(array_slice($referencePaths, 0, 1));

        $referenceBundle = $this->shouldPreferSingleReferenceModeForKling(
            (string) ($videoOptions['model'] ?? ''),
            $preferredReferenceAbs,
            $referenceReason,
            $compositionMeta
        )
            ? $this->buildKlingReferenceInputs([$preferredReferenceAbs], $preferredReferencePaths)
            : $this->buildKlingReferenceInputs($referenceAbsPool, $referencePaths);
        $referenceInputs = (array) ($referenceBundle['inputs'] ?? []);
        $requestMode = $kling->resolveRequestMode($referenceInputs);

        if ($locationSequenceMode && count($referenceInputs) > 1) {
            $referenceInputs = array_values(array_slice($referenceInputs, 0, 1));
            $requestMode = $kling->resolveRequestMode($referenceInputs, 'image');
            $referenceReason .= '_primary_location_anchor_only';
        }
        $referenceCap = $this->resolveKlingReferenceCap($item, $assetVariables);
        if (count($referenceInputs) > $referenceCap) {
            $referenceInputs = array_values(array_slice($referenceInputs, 0, $referenceCap));
            $requestMode = $kling->resolveRequestMode($referenceInputs, $referenceCap > 1 ? 'multi-image' : 'image');
            $referenceReason .= '_capped_' . $referenceCap . '_refs';
        }
        $requestSummary = (array) ($referenceBundle['summary'] ?? []);
        $requestSummary['identity_board_applied'] = $this->shouldUsePersonIdentityReferenceBoard($assetVariables, $referencePaths);
        $requestSummary['location_sequence_mode'] = $locationSequenceMode;
        $requestSummary['reference_count'] = count($referenceInputs);
        $requestSummary['reference_paths'] = array_values(array_slice($referencePaths, 0, 4));

        $klingOptions = [
            'request_mode' => $requestMode,
            'model' => (string) ($videoOptions['model'] ?? ''),
            'strict_model' => $this->isStrictVideoModelSelection('kling', is_array($item->ai_meta) ? $item->ai_meta : []),
            'mode' => (string) (config('kling.mode') ?: 'pro'),
            'cfg_scale' => $this->resolveKlingCfgScale($item, $assetVariables),
            'seconds' => (int) ($videoOptions['seconds'] ?? (int) (config('kling.video_seconds') ?: 5)),
            'size' => (string) ($videoOptions['size'] ?? '720x1280'),
            'negative_prompt' => $this->buildKlingNegativePrompt(
                $item,
                $assetVariables,
                $activeFeedbackRequest,
                $locationSequenceMode
            ),
            'external_task_id' => 'socialai-item-' . $item->id . '-' . now()->timestamp,
        ];

        $job = $kling->createVideoJob($videoPrompt, $referenceInputs, $klingOptions);
        $taskId = (string) ($job['id'] ?? '');
        $jobFinal = $kling->waitForVideoCompletion($taskId, (string) ($job['request_mode'] ?? $requestMode));
        $videoBytes = $kling->downloadVideoContent($jobFinal);
        $thumbBytes = $kling->downloadThumbnailContent($jobFinal);

        $videoExt = $this->detectVideoExtensionFromBytes($videoBytes);
        $videoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $videoExt;
        Storage::disk('public')->put($videoPath, $videoBytes);

        $thumbPath = null;
        if (is_string($thumbBytes) && $thumbBytes !== '') {
            $thumbExt = $this->detectImageExtensionFromBytes($thumbBytes);
            $thumbPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $thumbExt;
            Storage::disk('public')->put($thumbPath, $thumbBytes);
        }

        return [
            'source' => 'kling_video_generation',
            'provider' => 'kling',
            'video_id' => $taskId,
            'video_path' => $videoPath,
            'thumbnail_path' => $thumbPath,
            'reference_path' => $referencePaths[0] ?? '',
            'reference_paths' => array_values(array_slice($referencePaths, 0, 4)),
            'reference_reason' => $referenceReason,
            'reference_validation' => null,
            'composition_reference' => $compositionMeta,
            'generation_attempts' => 1,
            'job_status' => (string) (
                data_get($jobFinal, 'data.task_status')
                ?? data_get($jobFinal, 'task_status')
                ?? 'succeed'
            ),
            'brand_selection' => $brandDecision,
            'provider_fallback' => null,
            'request_summary' => $job['request_summary'] ?? null,
            'reference_input_summary' => $requestSummary,
        ];
    }

    /**
     * @param  array<int, string>  $referenceAbsPool
     * @param  array<int, string>  $referencePaths
     * @return array{inputs: array<int, string>, summary: array<string, mixed>}
     */
    public function resolveKlingCfgScale(ContentItem $item, array $assetVariables): float
    {
        $configured = (float) (config('kling.cfg_scale') ?: 0.78);
        $configured = max(0.3, min(1.0, $configured));
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $context = $this->videoSubjectContextText(
            $meta,
            (string) data_get($meta, 'manual_brief', ''),
            (string) data_get($meta, 'video_prompt', '')
        );
        if ($this->videoNeedsDualSubjectLock($meta, $context, $assetVariables)) {
            return max($configured, 0.86);
        }
        if ($this->hasPersonAssetVariable($assetVariables) || Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel') {
            return max($configured, 0.78);
        }
        return $configured;
    }
    public function resolveKlingReferenceCap(ContentItem $item, array $assetVariables): int
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $context = $this->videoSubjectContextText(
            $meta,
            (string) data_get($meta, 'manual_brief', ''),
            (string) data_get($meta, 'video_prompt', '')
        );
        if ($this->videoNeedsDualSubjectLock($meta, $context, $assetVariables)) {
            return 2;
        }
        if ($this->hasPersonAssetVariable($assetVariables) || Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel') {
            return 2;
        }
        return 3;
    }
    public function buildKlingReferenceInputs(array $referenceAbsPool, array $referencePaths): array
    {
        $referenceAbsPool = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $referenceAbsPool),
            fn ($value) => $value !== ''
        ));
        $referencePaths = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $referencePaths),
            fn ($value) => $value !== ''
        ));

        $inputs = [];
        $inputModes = [];
        $pairedPaths = [];
        $limit = max(1, min(4, (int) (config('kling.max_reference_images') ?: 4)));

        foreach (array_values(array_slice($referencePaths, 0, $limit)) as $index => $path) {
            $absolutePath = $referenceAbsPool[$index] ?? '';
            $input = $this->buildKlingReferenceInput($path, $absolutePath);
            if ($input === null) {
                continue;
            }

            $inputs[] = $input['value'];
            $inputModes[] = $input['mode'];
            $pairedPaths[] = $path;
        }

        if (empty($inputs)) {
            foreach (array_values(array_slice($referenceAbsPool, 0, $limit)) as $absolutePath) {
                $dataUri = $this->buildKlingDataUri($absolutePath);
                if ($dataUri === null) {
                    continue;
                }

                $inputs[] = $dataUri;
                $inputModes[] = 'data_uri';
            }
        }

        return [
            'inputs' => $inputs,
            'summary' => [
                'input_modes' => array_values(array_unique($inputModes)),
                'paired_reference_paths' => $pairedPaths,
            ],
        ];
    }

    /**
     * @return array{mode:string,value:string}|null
     */
    public function buildKlingReferenceInput(string $storagePath, string $absolutePath): ?array
    {
        if ($this->shouldPreferInlineKlingReference()) {
            $dataUri = $this->buildKlingDataUri($absolutePath);
            if ($dataUri !== null) {
                return [
                    'mode' => 'data_uri',
                    'value' => $dataUri,
                ];
            }
        }

        try {
            return [
                'mode' => 'public_url',
                'value' => PublicMediaUrl::build($storagePath),
            ];
        } catch (Throwable) {
            $dataUri = $this->buildKlingDataUri($absolutePath);
            if ($dataUri === null) {
                return null;
            }

            return [
                'mode' => 'data_uri',
                'value' => $dataUri,
            ];
        }
    }

    public function shouldPreferInlineKlingReference(): bool
    {
        $baseUrl = strtolower(trim((string) (config('kling.base_url') ?: '')));

        return str_contains($baseUrl, 'klingai.com');
    }

    public function buildKlingDataUri(string $absolutePath): ?string
    {
        $absolutePath = trim($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        return base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $activeFeedbackRequest
     */
    public function buildKlingNegativePrompt(
        ContentItem $item,
        array $assetVariables,
        array $activeFeedbackRequest,
        bool $locationSequenceMode
    ): string {
        $parts = [
            'identity drift',
            'different face',
            'different age',
            'duplicate subject',
            'extra people replacing the main subject',
            'uncanny eyes',
            'deformed hands',
            'bad anatomy',
            'plastic skin',
            'wax face',
            'airbrushed face',
            'cartoon look',
            'cgi render',
            '3d animation',
            'digital painting',
            'anime style',
            'illustration style',
            'beauty filter face',
            'doll face',
            'toy car look',
            'toy vehicle proportions',
            'unreal metallic reflections',
            'over-smoothed paint reflections',
            'text overlay',
            'watermark',
            'fake logo',
        ];

        if ($this->hasPersonAssetVariable($assetVariables)) {
            $parts[] = 'different hairstyle';
            $parts[] = 'different facial structure';
            $parts[] = 'subject inconsistency between shots';
        }

        if ($locationSequenceMode) {
            $parts[] = 'merged rooms';
        }

        if ($this->feedbackTargetsVisual($activeFeedbackRequest)) {
            $parts[] = 'too similar to previous version';
        }

        if ($locationSequenceMode) {
            $parts[] = 'changed architecture';
            $parts[] = 'invented interiors';
        }

        if (Str::lower(trim((string) ($item->format ?? 'post'))) === 'reel') {
            $parts[] = 'flat pacing';
            $parts[] = 'static brochure style';
        }

        return Str::limit(implode(', ', array_values(array_unique($parts))), 600, '');
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @return array<string, mixed>
     */
    public function generateVideoWithRunway(
        RunwayService $runway,
        OpenAiService $openAi,
        ContentItem $item,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referencePaths,
        string $referenceReason,
        array $generationReferenceAbsPool,
        array $imageReferencePathPool,
        array $validationReferenceAbsPool,
        bool $mustEnforceExplicitReferences,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions
    ): array {
        $videoOptions = $this->normalizeVideoOptionsForProvider('runway', $videoOptions);
        $runwayOptions = [
            'model' => (string) ($videoOptions['model'] ?? config('runway.model') ?: 'gen4.5'),
            'seconds' => (string) ($videoOptions['seconds'] ?? (string) (config('runway.video_seconds') ?: 8)),
            'size' => (string) ($videoOptions['size'] ?? config('openai.video_size') ?: '720x1280'),
            'strict_model' => $this->isStrictVideoModelSelection('runway', is_array($item->ai_meta) ? $item->ai_meta : []),
        ];

        $maxAttempts = $mustEnforceExplicitReferences ? 2 : 1;
        $lastValidation = null;
        $lastError = null;
        $generationAttemptCounter = 0;
        $runwayPlans = $this->buildRunwayRecoveryPlans($runwayOptions, $videoPrompt, $briefRaw);

        foreach ($runwayPlans as $planIndex => $runwayPlan) {
            $planOptions = $runwayOptions;
            $planOptions['model'] = (string) ($runwayPlan['model'] ?? $runwayOptions['model']);
            $planPromptBase = (string) ($runwayPlan['prompt'] ?? $videoPrompt);
            $planReason = trim((string) ($runwayPlan['reason'] ?? 'primary'));
            $planError = null;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $generationAttemptCounter++;
                $attemptPrompt = $planPromptBase;
                if ($attempt > 0) {
                    $attemptPrompt .= ' RIGENERAZIONE OBBLIGATORIA: il risultato precedente non rispettava tutti i riferimenti.';
                    $attemptPrompt .= ' Includi chiaramente ogni soggetto richiesto nelle immagini di input.';
                }

                $attemptReferenceAbs = $referenceAbs;
                $attemptReferencePath = $referencePath;
                $attemptReferencePaths = $referencePaths;
                $attemptReferenceReason = $referenceReason;
                if ($planReason !== '' && $planReason !== 'primary') {
                    $attemptReferenceReason .= '_' . $planReason;
                }
                $attemptTempPreparedPath = null;

                try {
                    try {
                        $job = $runway->createVideoJob($attemptPrompt, $attemptReferenceAbs, $planOptions);
                    } catch (Throwable $videoCreateError) {
                        if ($mustEnforceExplicitReferences && !empty($generationReferenceAbsPool)) {
                            $fallbackAbs = $generationReferenceAbsPool[0];
                            $fallbackPrepared = $this->prepareVideoReferenceForSize($fallbackAbs, (string) $planOptions['size']);
                            if ($fallbackPrepared) {
                                $attemptTempPreparedPath = $fallbackPrepared;
                                $attemptReferenceAbs = $fallbackPrepared;
                                $attemptReferenceReason = 'runway_retry_with_primary_reference_after_error_normalized';
                            } else {
                                $attemptReferenceAbs = $fallbackAbs;
                                $attemptReferenceReason = 'runway_retry_with_primary_reference_after_error';
                            }

                            $attemptReferencePath = $imageReferencePathPool[0] ?? $attemptReferencePath;
                            $attemptReferencePaths = array_values(array_filter(
                                [$attemptReferencePath],
                                fn ($v) => is_string($v) && $v !== ''
                            ));
                            $job = $runway->createVideoJob($attemptPrompt, $attemptReferenceAbs, $planOptions);
                        } else {
                            $attemptReferenceAbs = null;
                            $attemptReferencePath = null;
                            $attemptReferencePaths = [];
                            $attemptReferenceReason = 'runway_retry_without_reference_after_error';
                            $job = $runway->createVideoJob($attemptPrompt, null, $planOptions);
                        }
                    }

                    $videoId = (string) ($job['id'] ?? '');
                    $jobFinal = $runway->waitForVideoCompletion($videoId);
                    $videoBytes = $runway->downloadVideoContent($jobFinal);
                    $thumbBytes = $runway->downloadThumbnailContent($jobFinal);

                    $validation = null;
                    if ($mustEnforceExplicitReferences && is_string($thumbBytes) && $thumbBytes !== '') {
                        $tmpDir = storage_path('app/tmp');
                        if (!is_dir($tmpDir)) {
                            @mkdir($tmpDir, 0775, true);
                        }

                        $tmpThumbPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-validate-runway-' . Str::uuid()->toString() . '.' . $this->detectImageExtensionFromBytes($thumbBytes);
                        @file_put_contents($tmpThumbPath, $thumbBytes);
                        if (is_file($tmpThumbPath)) {
                            $validation = $openAi->validateVideoFrameWithReferences(
                                brief: $briefRaw !== '' ? $briefRaw : $fallbackPrompt,
                                frameAbsolutePath: $tmpThumbPath,
                                referenceAbsolutePaths: !empty($validationReferenceAbsPool)
                                    ? $validationReferenceAbsPool
                                    : array_slice($generationReferenceAbsPool, 0, 4)
                            );
                            @unlink($tmpThumbPath);
                        }

                        if (is_array($validation)) {
                            $lastValidation = $validation;
                            $allPresent = (bool) ($validation['all_present'] ?? false);
                            if (!$allPresent && ($attempt + 1) < $maxAttempts) {
                                continue;
                            }
                            if (!$allPresent) {
                                $attemptReferenceReason .= '_validation_failed_accept_last_attempt';
                            }
                        }
                    }

                    $videoExt = $this->detectVideoExtensionFromBytes($videoBytes);
                    $videoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $videoExt;
                    Storage::disk('public')->put($videoPath, $videoBytes);

                    $thumbPath = null;
                    if (is_string($thumbBytes) && $thumbBytes !== '') {
                        $thumbExt = $this->detectImageExtensionFromBytes($thumbBytes);
                        $thumbPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $thumbExt;
                        Storage::disk('public')->put($thumbPath, $thumbBytes);
                    }

                    $reelBlueprintSummary = $this->compactReelBlueprintSummary(
                        is_array(data_get($item->ai_meta, 'reel_blueprint', [])) ? (array) data_get($item->ai_meta, 'reel_blueprint', []) : []
                    );
                    $storyboardSummary = $this->compactStoryboardSummary(
                        is_array(data_get($item->ai_meta, 'storyboard_meta', [])) ? (array) data_get($item->ai_meta, 'storyboard_meta', []) : []
                    );

                    return [
                        'source' => 'runway_video_generation',
                        'provider' => 'runway',
                        'video_id' => $videoId,
                        'video_path' => $videoPath,
                        'thumbnail_path' => $thumbPath,
                        'reference_path' => $attemptReferencePath,
                        'reference_paths' => array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== '')),
                        'reference_reason' => $attemptReferenceReason,
                        'reference_validation' => $validation ?? $lastValidation,
                        'composition_reference' => $compositionMeta,
                        'generation_attempts' => $generationAttemptCounter,
                        'job_status' => (string) ($jobFinal['status'] ?? data_get($jobFinal, 'task.status', 'completed')),
                        'brand_selection' => $brandDecision,
                        'request_summary' => [
                            'mode' => 'image_to_video',
                            'model' => (string) ($planOptions['model'] ?? ''),
                            'seconds' => (string) ($planOptions['seconds'] ?? ''),
                            'size' => (string) ($planOptions['size'] ?? ''),
                            'has_prompt_image' => is_string($attemptReferenceAbs) && $attemptReferenceAbs !== '',
                            'reel_blueprint' => $reelBlueprintSummary,
                            'storyboard' => $storyboardSummary,
                            'retry_plan' => $planReason,
                        ],
                        'reference_input_summary' => [
                            'requested_reference_count' => count($imageReferencePathPool),
                            'active_reference_count' => count(array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== ''))),
                            'validation_reference_count' => count($validationReferenceAbsPool),
                            'reference_reason' => $attemptReferenceReason,
                        ],
                    ];
                } catch (Throwable $attemptError) {
                    $lastError = $attemptError;
                    $planError = $attemptError;
                } finally {
                    if (is_string($attemptTempPreparedPath) && $attemptTempPreparedPath !== '' && is_file($attemptTempPreparedPath)) {
                        @unlink($attemptTempPreparedPath);
                    }
                }
            }

            if ($planError instanceof Throwable) {
                $hasMorePlans = ($planIndex + 1) < count($runwayPlans);
                if (!$hasMorePlans || !$this->shouldRetryRunwayInsideProvider($planError)) {
                    throw $planError;
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new \RuntimeException('Runway video generation failed without explicit error.');
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<int, string>  $generationReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<int, string>  $validationReferenceAbsPool
     * @param  array<string, mixed>|null  $compositionMeta
     * @param  array<string, mixed>  $brandDecision
     * @param  array<string, mixed>  $videoOptions
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>|null  $providerFallback
     * @return array<string, mixed>
     */
    public function generateVideoWithOpenAi(
        OpenAiService $openAi,
        string $briefRaw,
        string $fallbackPrompt,
        string $videoPrompt,
        ?string $referenceAbs,
        ?string $referencePath,
        array $referencePaths,
        string $referenceReason,
        array $generationReferenceAbsPool,
        array $imageReferencePathPool,
        array $validationReferenceAbsPool,
        bool $mustEnforceExplicitReferences,
        ?array $compositionMeta,
        array $brandDecision,
        array $videoOptions,
        array $assetVariables,
        ?array $providerFallback = null
    ): array {
        $videoOptions = $this->normalizeVideoOptionsForProvider('openai', $videoOptions);
        $validationAttempts = $mustEnforceExplicitReferences ? 2 : 1;
        $lastValidation = null;
        $lastError = null;
        $promptVariants = [$videoPrompt];
        $moderationRetryPrompt = $this->buildOpenAiVideoModerationRetryPrompt(
            $videoPrompt,
            $briefRaw !== '' ? $briefRaw : $fallbackPrompt,
            $referencePaths,
            $assetVariables
        );
        if ($moderationRetryPrompt !== '' && $moderationRetryPrompt !== $videoPrompt) {
            $promptVariants[] = $moderationRetryPrompt;
        }

        foreach ($promptVariants as $promptIndex => $basePrompt) {
            for ($attempt = 0; $attempt < $validationAttempts; $attempt++) {
                $attemptPrompt = $basePrompt;
                if ($attempt > 0) {
                    $attemptPrompt .= ' RIGENERAZIONE OBBLIGATORIA: il risultato precedente non rispettava tutti i riferimenti.';
                    $attemptPrompt .= ' Includi chiaramente ogni soggetto richiesto nelle immagini di input.';
                }

                $attemptReferenceAbs = $referenceAbs;
                $attemptReferencePath = $referencePath;
                $attemptReferencePaths = $referencePaths;
                $attemptReferenceReason = $referenceReason;
                $attemptTempPreparedPath = null;

                try {
                    $job = null;
                    try {
                        $job = $openAi->createVideoJob($attemptPrompt, $attemptReferenceAbs, $videoOptions);
                    } catch (Throwable $videoCreateError) {
                        $msg = strtolower($videoCreateError->getMessage());
                        // Ritenta senza reference solo per errori specifici di validazione del reference
                        // (inpaint / frames field errors). Errori transitori (5xx) rilanciati subito.
                        $needsNoRefRetry = str_contains($msg, 'inpaint image must match')
                            || str_contains($msg, 'inpaint')
                            || str_contains($msg, 'input_reference')
                            || str_contains($msg, 'frames.')
                            || str_contains($msg, '"frames"')
                            || str_contains($msg, 'frame_position');

                        if (!$needsNoRefRetry) {
                            throw $videoCreateError;
                        }

                        if ($mustEnforceExplicitReferences && !empty($generationReferenceAbsPool)) {
                            $fallbackAbs = $generationReferenceAbsPool[0];
                            $fallbackPrepared = $this->prepareVideoReferenceForSize($fallbackAbs, (string) $videoOptions['size']);
                            if ($fallbackPrepared) {
                                $attemptTempPreparedPath = $fallbackPrepared;
                                $attemptReferenceAbs = $fallbackPrepared;
                                $attemptReferenceReason = 'retry_with_primary_reference_after_inpaint_error_normalized';
                            } else {
                                $attemptReferenceAbs = $fallbackAbs;
                                $attemptReferenceReason = 'retry_with_primary_reference_after_inpaint_error';
                            }

                            $attemptReferencePath = $imageReferencePathPool[0] ?? $attemptReferencePath;
                            $attemptReferencePaths = array_values(array_filter(
                                [$attemptReferencePath],
                                fn ($v) => is_string($v) && $v !== ''
                            ));
                            $job = $openAi->createVideoJob($attemptPrompt, $attemptReferenceAbs, $videoOptions);
                        } else {
                            $attemptReferenceAbs = null;
                            $attemptReferencePath = null;
                            $attemptReferencePaths = [];
                            $attemptReferenceReason = 'retry_without_reference_after_inpaint_error';
                            $job = $openAi->createVideoJob($attemptPrompt, null, $videoOptions);
                        }
                    }

                    $videoId = (string) ($job['id'] ?? '');
                    $jobFinal = $openAi->waitForVideoCompletion($videoId);
                    $videoBytes = $openAi->downloadVideoContent($videoId);
                    $thumbBytes = null;
                    try {
                        $thumbBytes = $openAi->downloadVideoThumbnail($videoId);
                    } catch (Throwable) {
                        $thumbBytes = null;
                    }

                    $validation = null;
                    if ($mustEnforceExplicitReferences && is_string($thumbBytes) && $thumbBytes !== '') {
                        $tmpDir = storage_path('app/tmp');
                        if (!is_dir($tmpDir)) {
                            @mkdir($tmpDir, 0775, true);
                        }
                        $tmpThumbPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-validate-' . Str::uuid()->toString() . '.' . $this->detectImageExtensionFromBytes($thumbBytes);
                        @file_put_contents($tmpThumbPath, $thumbBytes);
                        if (is_file($tmpThumbPath)) {
                            $validation = $openAi->validateVideoFrameWithReferences(
                                brief: $briefRaw !== '' ? $briefRaw : $fallbackPrompt,
                                frameAbsolutePath: $tmpThumbPath,
                                referenceAbsolutePaths: !empty($validationReferenceAbsPool)
                                    ? $validationReferenceAbsPool
                                    : array_slice($generationReferenceAbsPool, 0, 4)
                            );
                            @unlink($tmpThumbPath);
                        }

                        if (is_array($validation)) {
                            $lastValidation = $validation;
                            $allPresent = (bool) ($validation['all_present'] ?? false);
                            if (!$allPresent && ($attempt + 1) < $validationAttempts) {
                                continue;
                            }
                            if (!$allPresent) {
                                $attemptReferenceReason .= '_validation_failed_accept_last_attempt';
                            }
                        }
                    }

                    $videoExt = $this->detectVideoExtensionFromBytes($videoBytes);
                    $videoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $videoExt;
                    Storage::disk('public')->put($videoPath, $videoBytes);

                    $thumbPath = null;
                    if (is_string($thumbBytes) && $thumbBytes !== '') {
                        $thumbExt = $this->detectImageExtensionFromBytes($thumbBytes);
                        $thumbPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $thumbExt;
                        Storage::disk('public')->put($thumbPath, $thumbBytes);
                    }

                    return [
                        'source' => 'sora_video_generation',
                        'provider' => 'openai',
                        'video_id' => $videoId,
                        'video_path' => $videoPath,
                        'thumbnail_path' => $thumbPath,
                        'reference_path' => $attemptReferencePath,
                        'reference_paths' => array_values(array_filter($attemptReferencePaths, fn ($v) => is_string($v) && $v !== '')),
                        'reference_reason' => $attemptReferenceReason,
                        'reference_validation' => $validation ?? $lastValidation,
                        'composition_reference' => $compositionMeta,
                        'generation_attempts' => $attempt + 1 + ($promptIndex * $validationAttempts),
                        'job_status' => (string) ($jobFinal['status'] ?? 'completed'),
                        'brand_selection' => $brandDecision,
                        'provider_fallback' => $providerFallback,
                    ];
                } catch (Throwable $attemptError) {
                    $lastError = $attemptError;
                    if ($this->isOpenAiVideoModerationBlock($attemptError)) {
                        if (($promptIndex + 1) < count($promptVariants)) {
                            break;
                        }
                        throw $attemptError;
                    }

                    if (($attempt + 1) >= $validationAttempts) {
                        throw $attemptError;
                    }
                } finally {
                    if (is_string($attemptTempPreparedPath) && $attemptTempPreparedPath !== '' && is_file($attemptTempPreparedPath)) {
                        @unlink($attemptTempPreparedPath);
                    }
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new \RuntimeException('Video generation failed without explicit error.');
    }

    public function shouldFallbackFromRunwayToOpenAi(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));

        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'missing runway_api_key')) {
            return false;
        }

        if (str_contains($message, 'runway video create error (400)')) {
            return false;
        }

        return str_contains($message, 'runway video generation failed')
            || str_contains($message, 'runway completed payload missing downloadable video url')
            || str_contains($message, 'runway asset download error')
            || str_contains($message, 'runway video generation timeout')
            || str_contains($message, 'curl error')
            || str_contains($message, 'failed to connect');
    }

    public function shouldRetryRunwayInsideProvider(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));

        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'missing runway_api_key')) {
            return false;
        }

        if (str_contains($message, 'runway video create error (400)')) {
            return false;
        }

        if (str_contains($message, 'safety_rejected')) {
            return false;
        }

        return str_contains($message, 'runway video generation failed: video_generation_failed')
            || str_contains($message, 'runway video generation failed: video_generation_failed (status=')
            || str_contains($message, 'runway video generation timeout')
            || str_contains($message, 'runway asset download error')
            || str_contains($message, 'runway completed payload missing downloadable video url')
            || $this->isTransientNetworkError($error);
    }

    /**
     * @param  array<string, mixed>  $runwayOptions
     * @return array<int, array{model:string,prompt:string,reason:string}>
     */
    public function buildRunwayRecoveryPlans(array $runwayOptions, string $videoPrompt, string $briefRaw): array
    {
        $plans = [];
        $baseModel = $this->normalizeRunwayVideoModel((string) ($runwayOptions['model'] ?? 'gen4.5'));
        $basePrompt = trim($videoPrompt);
        $stabilityPrompt = $this->buildRunwayStabilityRetryPrompt($videoPrompt, $briefRaw);

        $pushPlan = function (string $model, string $prompt, string $reason) use (&$plans): void {
            $model = $this->normalizeRunwayVideoModel($model);
            $prompt = trim($prompt);
            if ($model === '' || $prompt === '') {
                return;
            }

            $key = strtolower($model) . '|' . sha1($prompt);
            if (isset($plans[$key])) {
                return;
            }

            $plans[$key] = [
                'model' => $model,
                'prompt' => $prompt,
                'reason' => $reason,
            ];
        };

        $pushPlan($baseModel, $basePrompt, 'primary');

        if ((bool) ($runwayOptions['strict_model'] ?? false)) {
            return array_values($plans);
        }

        if ($baseModel !== 'gen4.5') {
            $pushPlan('gen4.5', $basePrompt, 'gen45_model_retry');
        }

        if ($stabilityPrompt !== '' && $stabilityPrompt !== $basePrompt) {
            $pushPlan('gen4.5', $stabilityPrompt, 'gen45_stability_retry');
        }

        return array_values($plans);
    }

    public function isStrictVideoModelSelection(string $provider, array $meta = []): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return false;
        }

        if (array_key_exists('video_model_strict', $meta)) {
            return (bool) data_get($meta, 'video_model_strict', false);
        }

        return match ($provider) {
            'runway' => (bool) config('runway.strict_model', false),
            'google_veo' => (bool) config('google_veo.strict_model', false),
            'kling' => (bool) config('kling.strict_model', false),
            default => false,
        };
    }

    public function buildRunwayStabilityRetryPrompt(string $videoPrompt, string $briefRaw): string
    {
        $source = trim($briefRaw !== '' ? $briefRaw : $videoPrompt);
        $parts = [
            'Create one coherent vertical 9:16 social video with one clear subject and one real environment.',
            'Keep identity, styling, location and camera logic stable from start to end.',
            'Use a simple premium commercial action with realistic motion and natural light.',
            'Avoid scene morphing, architecture changes, extra subjects, heavy transitions, text and watermark.',
        ];

        if ($source !== '') {
            $parts[] = 'Priority brief: ' . Str::limit($source, 180, '');
        }

        return Str::limit(
            trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))),
            max(300, min(900, (int) (config('runway.max_prompt_chars') ?: 980))),
            ''
        );
    }

    public function shouldFallbackFromKlingToSecondaryProvider(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'missing kling_access_key') || str_contains($message, 'missing kling_secret_key')) {
            return false;
        }

        if (str_contains($message, 'kling video create error (400)')) {
            return false;
        }

        return str_contains($message, 'kling video generation timeout')
            || str_contains($message, 'kling video retrieve error (500)')
            || str_contains($message, 'kling video retrieve error (502)')
            || str_contains($message, 'kling video retrieve error (503)')
            || str_contains($message, 'kling video retrieve error (504)')
            || str_contains($message, 'server error')
            || str_contains($message, 'server_error')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'gateway timeout')
            || str_contains($message, 'processing')
            || str_contains($message, 'blocked by our moderation system')
            || str_contains($message, 'moderation system')
            || $this->isTransientNetworkError($error);
    }

    public function shouldFallbackFromGoogleVeoToSecondaryProvider(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'missing google_veo_api_key')) {
            return false;
        }

        if (str_contains($message, 'google veo video create error (400)')) {
            return false;
        }

        return str_contains($message, 'google veo video generation timeout')
            || str_contains($message, 'google veo video create error (417)')
            || str_contains($message, 'google veo video retrieve error (500)')
            || str_contains($message, 'google veo video retrieve error (502)')
            || str_contains($message, 'google veo video retrieve error (503)')
            || str_contains($message, 'google veo video retrieve error (504)')
            || str_contains($message, 'google veo completed payload missing downloadable video url')
            || str_contains($message, 'google veo video download error')
            || str_contains($message, 'google veo video download returned an empty body')
            || str_contains($message, 'invalid google veo')
            || str_contains($message, 'server error')
            || str_contains($message, 'server_error')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'gateway timeout')
            || $this->isTransientNetworkError($error);
    }

    /**
     * @return array<int, string>
     */
    public function secondaryVideoProvidersForKlingFailure(): array
    {
        return $this->capabilityRegistry()->fallbackProviders('kling', 'video');
    }

    /**
     * @return array<int, string>
     */
    public function secondaryVideoProvidersForGoogleVeoFailure(): array
    {
        return $this->capabilityRegistry()->fallbackProviders('google_veo', 'video');
    }

    public function shouldFallbackFromOpenAiToSecondaryProvider(Throwable $error): bool
    {
        if ($this->isOpenAiVideoModerationBlock($error)) {
            return false;
        }

        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        // Errori di validazione reference: non fare fallback, l'utente deve correggere
        if (str_contains($message, 'inpaint image must match')
            || str_contains($message, 'inpaint')) {
            return false;
        }

        return str_contains($message, 'openai video create error (404)')
            || str_contains($message, 'openai video retrieve error (404)')
            || str_contains($message, 'status code 404')
            || str_contains($message, 'openai video create error (500)')
            || str_contains($message, 'openai video retrieve error (500)')
            || str_contains($message, 'openai video create error (502)')
            || str_contains($message, 'openai video retrieve error (502)')
            || str_contains($message, 'openai video create error (503)')
            || str_contains($message, 'openai video retrieve error (503)')
            || str_contains($message, 'openai video create error (504)')
            || str_contains($message, 'openai video retrieve error (504)')
            || str_contains($message, 'openai video create error (429)')
            || str_contains($message, 'server_error')
            || str_contains($message, 'server error')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'gateway timeout')
            || str_contains($message, 'video generation timeout after')
            || str_contains($message, 'url di download non trovato')
            || $this->isTransientNetworkError($error);
    }

    public function shouldFallbackFromNanoBananaToOpenAi(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'missing nanobanana_api_key')) {
            return false;
        }

        if (str_contains($message, 'nanobanana image error (400)')
            || str_contains($message, 'nanobanana image edit error (400)')
            || str_contains($message, 'promptfeedback.blockreason')
            || str_contains($message, 'blockreason=')
            || str_contains($message, 'safety')) {
            return false;
        }

        return str_contains($message, 'missing base64 image field in nanobanana response')
            || str_contains($message, 'missing base64 image field in nanobanana edit response')
            || str_contains($message, 'finishreason=image_other')
            || str_contains($message, 'invalid nanobanana image response payload')
            || str_contains($message, 'invalid nanobanana image edit response payload')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'gateway timeout')
            || $this->isTransientNetworkError($error);
    }

    /**
     * @return array<int, string>
     */
    public function secondaryVideoProvidersForOpenAiFailure(bool $hasReferencePool): array
    {
        return $this->capabilityRegistry()->fallbackProviders('openai', 'video');
    }

    public function isVideoProviderConfigured(string $provider): bool
    {
        return $this->capabilityRegistry()->isConfigured($provider, 'video');
    }

    /**
     * @param  array<int, string>  $referencePaths
     */
    public function buildOpenAiVideoFallbackPrompt(string $videoPrompt, string $briefRaw, array $referencePaths): string
    {
        $prompt = trim($videoPrompt);
        $referenceCount = count(array_filter($referencePaths, fn ($path) => is_string($path) && $path !== ''));

        $prompt .= ' Fallback di sicurezza: privilegia un output stabile e pubblicabile.';
        if ($referenceCount > 1) {
            $prompt .= ' Se ci sono piu ambienti reali, mostrali in sequenza con transizioni naturali invece di fonderli in un unico spazio impossibile.';
        }
        if (trim($briefRaw) !== '') {
            $prompt .= ' Brief utente prioritario: ' . trim($briefRaw) . '.';
        }

        return $prompt;
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    public function buildOpenAiVideoModerationRetryPrompt(
        string $videoPrompt,
        string $briefRaw,
        array $referencePaths,
        array $assetVariables
    ): string {
        $safePrompt = $this->buildSafeCommercialVideoPrompt($videoPrompt, $briefRaw, $referencePaths, $assetVariables);
        if ($safePrompt === '') {
            return '';
        }

        return $safePrompt . ' Retry di sicurezza: mantieni il contenuto pulito, professionale e rispettoso.';
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    public function prepareOpenAiVideoPromptForExecution(
        string $videoPrompt,
        string $briefRaw,
        array $referencePaths,
        array $assetVariables
    ): string {
        if (!$this->shouldUseOpenAiVideoModerationGuard($videoPrompt, $briefRaw, $assetVariables)) {
            return $videoPrompt;
        }

        $safePrompt = $this->buildSafeCommercialVideoPrompt($videoPrompt, $briefRaw, $referencePaths, $assetVariables);

        return $safePrompt !== '' ? $safePrompt : $videoPrompt;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    public function personIdentityVideoInstruction(array $assetVariables): string
    {
        $row = $this->singleResolvedPersonVariable($assetVariables);
        if ($row === null) {
            return '';
        }

        $name = trim((string) ($row['name'] ?? ''));
        $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
        $role = trim((string) ($profile['role'] ?? ''));
        $immutable = trim((string) ($profile['immutable_traits'] ?? ''));
        $lookNotes = trim((string) ($profile['look_notes'] ?? ''));
        $stylingNotes = trim((string) ($profile['styling_notes'] ?? ''));
        $promptNotes = trim((string) ($profile['prompt_notes'] ?? ''));

        $parts = [];
        $parts[] = $name !== '' ? "Persona di riferimento del brand: {$name}." : 'Persona di riferimento del brand presente nei riferimenti.';
        if ($role !== '') {
            $parts[] = "Ruolo reale: {$role}.";
        }
        if ($immutable !== '') {
            $parts[] = "Tratti da non cambiare mai: {$immutable}.";
        }
        if ($lookNotes !== '') {
            $parts[] = "Aspetto e presenza: {$lookNotes}.";
        }
        if ($stylingNotes !== '') {
            $parts[] = "Stile e presenza scenica: {$stylingNotes}.";
        }
        if ($promptNotes !== '') {
            $parts[] = "Indicazioni operative: {$promptNotes}.";
        }
        if (!empty((array) ($profile['shot_summary'] ?? []))) {
            $parts[] = 'Usa il pack multi-angolo come board identitaria dello stesso soggetto, non come persone diverse.';
        }
        if (trim((string) ($profile['reference_video_path'] ?? '')) !== '') {
            $parts[] = 'Il video reale di riferimento serve come guida per postura, mimica e presenza della stessa persona.';
        }
        $parts[] = 'Non cambiare identita del soggetto tra una generazione e l altra: varia scena e regia, non il volto.';

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 420, '');
    }

    /**
     * @param  array<int, string>  $referencePaths
     * @param  array<string, mixed>  $assetVariables
     */
    public function buildSafeCommercialVideoPrompt(
        string $videoPrompt,
        string $briefRaw,
        array $referencePaths,
        array $assetVariables
    ): string {
        $source = $this->sanitizeVideoPromptForSafety($briefRaw !== '' ? $briefRaw : $videoPrompt, $assetVariables);
        $hasPersonVariable = $this->hasPersonAssetVariable($assetVariables);
        $referenceCount = count(array_filter($referencePaths, fn ($path) => is_string($path) && $path !== ''));

        $parts = [
            'Crea un breve video verticale 9:16 realistico, pulito e adatto ai social.',
            'Una scena chiara per volta, movimenti morbidi, luce naturale e tono professionale.',
            'Niente nudita, niente sensualita, niente contatto ambiguo, niente focus insistito sul corpo, niente close-up estremi, niente testo o watermark.',
        ];

        if ($hasPersonVariable) {
            $parts[] = 'Se compare una persona di riferimento del brand, trattala come soggetto adulto e professionale, con abbigliamento adeguato, postura naturale e gesti rispettosi.';
        }

        if ($this->needsWellnessSafetyLanguage($source)) {
            $parts[] = 'Se il contesto e wellness o beauty, rappresentalo come trattamento professionale e rispettoso in un ambiente curato, senza sensualizzare la scena.';
        }

        if ($referenceCount > 1) {
            $parts[] = 'Se hai piu riferimenti, usali come scene coerenti e separate, non come collage o fusione impossibile.';
        }

        if ($source !== '') {
            $parts[] = 'Sintesi contenuto: ' . Str::limit($source, 240, '');
        }

        return Str::limit(trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''))), 760, '');
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    public function sanitizeVideoPromptForSafety(string $text, array $assetVariables): string
    {
        $sanitized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($sanitized === '') {
            return '';
        }

        foreach ($this->personVariableNames($assetVariables) as $name) {
            $pattern = '/\b' . preg_quote($name, '/') . '\b/ui';
            $sanitized = (string) preg_replace($pattern, 'la persona di riferimento del brand', $sanitized);
        }

        $replacements = [
            '/\bmassaggi?\s+tecnic[ioae]+\b/ui' => 'trattamento professionale',
            '/\bmassaggi?\b/ui' => 'trattamento benessere',
            '/\bmassage\b/ui' => 'wellness treatment',
            '/\bmassaging\b/ui' => 'performing a wellness treatment',
            '/\bschiena\b/ui' => 'parte alta del corpo',
            '/\bspalle\b/ui' => 'parte alta del corpo',
            '/\bback\b/ui' => 'upper body',
            '/\bshoulders\b/ui' => 'upper body',
            '/\btocco\b/ui' => 'gesto professionale',
            '/\btouch\b/ui' => 'professional technique',
            '/\bcliente\b/ui' => 'cliente adulto',
            '/\bclient\b/ui' => 'adult client',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $sanitized = (string) preg_replace($pattern, $replacement, $sanitized);
        }

        $sanitized = trim(preg_replace('/\s+/u', ' ', $sanitized) ?? $sanitized);

        return Str::limit($sanitized, 260, '');
    }

    public function isOpenAiVideoModerationBlock(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'blocked by our moderation system')
            || str_contains($message, 'moderation system')
            || str_contains($message, 'safety system')
            || str_contains($message, 'content policy')
            || str_contains($message, 'policy violation')
            || str_contains($message, 'disallowed content');
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    public function shouldUseOpenAiVideoModerationGuard(string $videoPrompt, string $briefRaw, array $assetVariables): bool
    {
        $source = trim($videoPrompt . ' ' . $briefRaw);
        if ($source === '') {
            return false;
        }

        if ($this->hasPersonAssetVariable($assetVariables) && $this->needsWellnessSafetyLanguage($source)) {
            return true;
        }

        $normalized = Str::lower($this->normalizeText($source));
        foreach (['spalle', 'schiena', 'touch', 'tocco', 'massagg', 'corpo', 'body'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    public function hasPersonAssetVariable(array $assetVariables): bool
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));

        foreach ($resolved as $row) {
            if (strtolower(trim((string) ($row['kind'] ?? 'custom'))) === 'person') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>|null
     */
    public function singleResolvedPersonVariable(array $assetVariables): ?array
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        $persons = array_values(array_filter(
            $resolved,
            fn ($row) => strtolower(trim((string) ($row['kind'] ?? 'custom'))) === 'person'
        ));

        return count($persons) === 1 ? $persons[0] : null;
    }

    public function videoSubjectContextText(array $meta, string $briefRaw = '', string $videoPrompt = ''): string
    {
        $parts = [
            trim($briefRaw),
            trim((string) data_get($meta, 'manual_brief', '')),
            trim($videoPrompt),
            trim((string) data_get($meta, 'video_prompt', '')),
            trim((string) data_get($meta, 'item_brain.angle', '')),
            trim((string) data_get($meta, 'editorial.angle', '')),
        ];

        return $this->normalizeText(implode(' ', array_values(array_unique(array_filter(
            $parts,
            fn ($value) => is_string($value) && trim($value) !== ''
        )))));
    }

    public function videoNeedsDualSubjectLock(array $meta, string $contextText, array $assetVariables): bool
    {
        if (!$this->hasPersonAssetVariable($assetVariables)) {
            return false;
        }

        return $this->primaryVideoProductLikeRow($meta, $assetVariables, $contextText) !== null;
    }

    public function subjectLockVideoInstruction(array $meta, string $contextText, array $assetVariables): string
    {
        if (!$this->videoNeedsDualSubjectLock($meta, $contextText, $assetVariables)) {
            return '';
        }

        $productLabel = $this->productLikeRowName($this->primaryVideoProductLikeRow($meta, $assetVariables, $contextText));
        if ($productLabel === '') {
            $productLabel = 'il veicolo o prodotto richiesto nel brief';
        }

        return "Vincolo di scena: la persona del brand e {$productLabel} devono restare entrambi soggetti principali del reel. Non trasformare il video in un ritratto della sola persona: {$productLabel} deve essere chiaramente visibile nel hook, nello sviluppo e nel payoff finale. Se il brief specifica marca, modello o colore, rispettali senza cambiarli.";
    }

    public function primaryVideoProductLikeRow(array $meta, array $assetVariables, string $contextText = ''): ?array
    {
        $candidates = [];

        foreach ((array) data_get($meta, 'asset_identity.slots', []) as $row) {
            if (is_array($row)) {
                $candidates[] = $row;
            }
        }

        foreach ($this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', [])) as $row) {
            if (is_array($row)) {
                $candidates[] = $row;
            }
        }

        foreach ($candidates as $row) {
            if ($this->rowLooksProductLike($row)) {
                return $row;
            }

            $kind = strtolower(trim((string) ($row['kind'] ?? 'custom')));
            if ($kind !== 'person' && $kind !== 'location' && $contextText !== '' && $this->assetVariableMatchesBrief($contextText, $row)) {
                return $row;
            }
        }

        if ($this->videoContextMentionsProduct($contextText)) {
            return [
                'name' => $this->extractProductHintFromContext($contextText),
                'kind' => 'product',
                'asset_role' => 'hero_product',
            ];
        }

        return null;
    }

    public function rowLooksProductLike(array $row): bool
    {
        $kind = strtolower(trim((string) ($row['kind'] ?? 'custom')));
        if ($kind === 'product') {
            return true;
        }

        $haystack = $this->normalizeText(implode(' ', array_filter([
            (string) ($row['name'] ?? ''),
            (string) ($row['slug'] ?? ''),
            (string) ($row['asset_role'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) data_get($row, 'profile.role', ''),
            (string) data_get($row, 'profile.identity_summary', ''),
            (string) data_get($row, 'profile.descriptor.summary', ''),
        ])));

        foreach (['product', 'prodotto', 'hero product', 'hero_product', 'vehicle', 'veicolo', 'auto', 'car', 'ferrari', 'lamborghini', 'porsche', 'mercedes', 'bmw', 'audi', 'alfa romeo', 'maserati', 'tesla', 'suv', 'coupe', 'spyder', 'supercar'] as $needle) {
            $needle = $this->normalizeText($needle);
            if ($needle !== '' && str_contains(' ' . $haystack . ' ', ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    public function videoContextMentionsProduct(string $contextText): bool
    {
        $contextText = $this->normalizeText($contextText);
        if ($contextText === '') {
            return false;
        }

        foreach (['ferrari', 'lamborghini', 'porsche', 'bmw', 'mercedes', 'audi', 'maserati', 'tesla', 'auto', 'macchina', 'car', 'vehicle', 'veicolo', 'prodotto', 'modello', 'supercar', 'suv', 'coupe', 'spyder', 'cabrio', 'roadster'] as $needle) {
            $needle = $this->normalizeText($needle);
            if ($needle !== '' && str_contains(' ' . $contextText . ' ', ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    public function extractProductHintFromContext(string $contextText): string
    {
        $normalized = $this->normalizeText($contextText);
        if ($normalized === '') {
            return '';
        }

        $brand = '';
        foreach (['ferrari', 'lamborghini', 'porsche', 'maserati', 'bmw', 'mercedes', 'audi', 'tesla', 'alfa romeo'] as $candidate) {
            $needle = $this->normalizeText($candidate);
            if ($needle !== '' && str_contains(' ' . $normalized . ' ', ' ' . $needle . ' ')) {
                $brand = $candidate;
                break;
            }
        }

        $color = '';
        foreach (['rossa', 'rosso', 'red', 'nera', 'nero', 'black', 'bianca', 'bianco', 'white', 'gialla', 'giallo', 'yellow', 'blu', 'blue', 'grigia', 'grigio', 'grey', 'gray'] as $candidate) {
            $needle = $this->normalizeText($candidate);
            if ($needle !== '' && str_contains(' ' . $normalized . ' ', ' ' . $needle . ' ')) {
                $color = $candidate;
                break;
            }
        }

        if ($brand !== '' && $color !== '') {
            return trim($brand . ' ' . $color);
        }
        if ($brand !== '') {
            return $brand;
        }
        if ($color !== '') {
            return 'auto ' . $color;
        }
        if ($this->videoContextMentionsProduct($normalized)) {
            return 'il veicolo o prodotto richiesto nel brief';
        }

        return '';
    }

    public function productLikeRowName(?array $row): string
    {
        if (!is_array($row)) {
            return '';
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $descriptor = trim((string) (
            ($row['description'] ?? '')
            ?: data_get($row, 'profile.identity_summary', '')
            ?: data_get($row, 'profile.descriptor.summary', '')
        ));

        return $descriptor;
    }

    public function needsWellnessSafetyLanguage(string $text): bool
    {
        $normalized = Str::lower($this->normalizeText($text));

        foreach (['massagg', 'trattamento', 'wellness', 'spa', 'olist', 'beauty', 'benessere'] as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @return array<int, string>
     */
    public function personVariableNames(array $assetVariables): array
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));

        return collect($resolved)
            ->filter(fn ($row) => strtolower(trim((string) ($row['kind'] ?? 'custom'))) === 'person')
            ->map(fn ($row) => trim((string) ($row['name'] ?? '')))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $imageReferenceAbsPool
     * @param  array<int, string>  $imageReferencePathPool
     * @param  array<string, mixed>  $assetVariables
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public function prioritizeVideoReferencePoolsForPersonVariable(
        array $imageReferenceAbsPool,
        array $imageReferencePathPool,
        array $assetVariables,
        array $meta = [],
        string $contextText = ''
    ): array {
        if ($this->videoNeedsDualSubjectLock($meta, $contextText, $assetVariables)) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $row = $this->singleResolvedPersonVariable($assetVariables);
        if ($row === null) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $pathToAbs = [];
        foreach ($imageReferencePathPool as $index => $path) {
            $path = trim((string) $path);
            $abs = isset($imageReferenceAbsPool[$index]) ? trim((string) $imageReferenceAbsPool[$index]) : '';
            if ($path === '' || $abs === '') {
                continue;
            }
            $pathToAbs[$path] = $abs;
        }

        if (empty($pathToAbs)) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $orderedPaths = $this->orderedPersonImagePaths($row, array_keys($pathToAbs));
        if (empty($orderedPaths)) {
            return [$imageReferenceAbsPool, $imageReferencePathPool];
        }

        $orderedAbs = array_values(array_map(fn ($path) => $pathToAbs[$path], $orderedPaths));

        return [$orderedAbs, $orderedPaths];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $referencePaths
     */
    public function shouldUsePersonIdentityReferenceBoard(array $assetVariables, array $referencePaths): bool
    {
        return $this->singleResolvedPersonVariable($assetVariables) !== null
            && count(array_values(array_filter($referencePaths, fn ($path) => is_string($path) && trim($path) !== ''))) >= 2;
    }

    /**
     * Quando usiamo Sora con una sola persona reale del brand, preferiamo l anchor primaria invece del collage:
     * e piu stabile per il volto e lascia le altre reference al prompt/validator.
     *
     * @param  array<string, mixed>  $assetVariables
     * @param  array<int, string>  $referencePaths
     */
    public function shouldUseOpenAiPrimaryPersonReference(
        string $videoProvider,
        bool $dualSubjectLock,
        array $assetVariables,
        array $referencePaths
    ): bool {
        return $videoProvider === 'openai'
            && !$dualSubjectLock
            && $this->shouldUsePersonIdentityReferenceBoard($assetVariables, $referencePaths);
    }

    public function shouldAttemptLockedVideoSceneReference(string $videoProvider, bool $dualSubjectLock): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>|null  $compositionReference
     */
    public function shouldUseLockedVideoSceneReference(?array $compositionReference, string $videoProvider, bool $dualSubjectLock): bool
    {
        if (!$this->shouldAttemptLockedVideoSceneReference($videoProvider, $dualSubjectLock)) {
            return false;
        }

        if (!is_array($compositionReference) || empty($compositionReference['abs'])) {
            return false;
        }

        return (bool) ($compositionReference['all_present'] ?? false);
    }

    /**
     * @param  array<string, mixed>|null  $compositionMeta
     */
    public function shouldPreferSingleReferenceModeForKling(
        string $model,
        string $referenceAbs,
        string $referenceReason = '',
        ?array $compositionMeta = null
    ): bool {
        $model = strtolower(trim($model));
        $referenceAbs = trim($referenceAbs);
        $referenceReason = strtolower(trim($referenceReason));
        $compositionMode = strtolower(trim((string) data_get($compositionMeta, 'mode', '')));

        if ($referenceAbs === '') {
            return false;
        }

        if (
            str_starts_with($model, 'kling-v3')
            || $model === 'kling-video-o1'
        ) {
            return true;
        }

        return str_contains($referenceReason, 'collage_reference')
            || str_contains($referenceReason, 'locked_scene_reference')
            || str_contains($referenceReason, 'identity_reference_board')
            || in_array($compositionMode, ['locked_scene_reference_rejected', 'person_identity_reference_board'], true)
            || (bool) data_get($compositionMeta, 'used', false);
    }

    /**
     * Valida anche le identita persistenti del brand, non solo le reference esplicite dell utente.
     *
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $referenceAbsPool
     */
    public function shouldValidateVideoReferenceMatch(
        bool $hasExplicitReferences,
        bool $locationSequenceMode,
        array $assetVariables,
        array $meta = [],
        array $referenceAbsPool = []
    ): bool {
        $referenceAbsPool = array_values(array_filter(
            array_map(fn ($path) => trim((string) $path), $referenceAbsPool),
            fn (string $path) => $path !== ''
        ));

        if ($locationSequenceMode || empty($referenceAbsPool)) {
            return false;
        }

        if ($hasExplicitReferences) {
            return true;
        }

        if (!empty((array) data_get($meta, 'asset_identity.slots', []))) {
            return true;
        }

        return $this->singleResolvedPersonVariable($assetVariables) !== null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $availablePaths
     * @return array<int, string>
     */
    public function orderedPersonImagePaths(array $row, array $availablePaths): array
    {
        $availableLookup = array_fill_keys($availablePaths, true);
        $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
        $shotSummary = is_array($profile['shot_summary'] ?? null) ? $profile['shot_summary'] : [];
        $identityPack = is_array($row['identity_pack'] ?? null)
            ? $row['identity_pack']
            : app(AssetIdentityService::class)->synthesizeIdentityPackFromRow($row);

        $slotPriority = [
            'front',
            'three_quarter_left',
            'three_quarter_right',
            'half_body',
            'profile',
        ];

        $ordered = [];
        $canonicalAssets = collect((array) data_get($identityPack, 'canonical_assets', []))
            ->filter(fn ($asset) => is_array($asset))
            ->sortByDesc(fn (array $asset) => (bool) ($asset['is_primary'] ?? false))
            ->values()
            ->all();

        foreach ($canonicalAssets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path !== '' && isset($availableLookup[$path])) {
                $ordered[] = $path;
                unset($availableLookup[$path]);
            }
        }

        foreach ($slotPriority as $slot) {
            foreach ($shotSummary as $shot) {
                if (!is_array($shot)) {
                    continue;
                }
                if (trim((string) ($shot['slot'] ?? '')) !== $slot) {
                    continue;
                }
                $path = trim((string) ($shot['path'] ?? ''));
                if ($path !== '' && isset($availableLookup[$path])) {
                    $ordered[] = $path;
                    unset($availableLookup[$path]);
                }
            }
        }

        foreach ($availablePaths as $path) {
            if (isset($availableLookup[$path])) {
                $ordered[] = $path;
                unset($availableLookup[$path]);
            }
        }

        return array_values(array_unique($ordered));
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    public function filterReferenceImagePaths(array $paths): array
    {
        $disk = Storage::disk('public');
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif'];

        return array_values(array_filter(
            array_unique(array_map(fn ($path) => trim((string) $path), $paths)),
            function (string $path) use ($disk, $allowed): bool {
                if ($path === '' || !$disk->exists($path)) {
                    return false;
                }

                $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

                return in_array($extension, $allowed, true);
            }
        ));
    }

    public function applyIdentityPackReferenceSelection(
        array $paths,
        array $assetVariables,
        array $assetIdentity = [],
        bool $strictAssetMode = false,
        array $assetScoring = []
    ): array {
        $paths = array_values(array_unique(array_filter(array_map(
            fn ($path) => trim((string) $path),
            $paths
        ))));

        $rankedPaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($assetScoring, 'reference_paths', [])
        )));
        $fallbackPaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($assetScoring, 'fallback_paths', [])
        )));
        $selectionArea = Str::lower(trim((string) data_get($assetScoring, 'selection_area', '')));
        $selectionProvider = Str::lower(trim((string) data_get($assetScoring, 'provider', '')));
        $protectedSlotPaths = $this->identityProtectedReferencePaths($assetVariables, $assetIdentity);
        $protectedSlotPaths = array_values(array_filter(
            $protectedSlotPaths,
            fn ($path) => in_array($path, $paths, true) || in_array($path, $rankedPaths, true) || in_array($path, $fallbackPaths, true)
        ));
        if (!empty($rankedPaths)) {
            if ($selectionArea === 'video' && $selectionProvider !== 'openai' && !empty($protectedSlotPaths)) {
                $rankedPaths = array_values(array_unique(array_merge($protectedSlotPaths, $rankedPaths)));
            }
            $ordered = array_values(array_unique(array_merge($rankedPaths, $strictAssetMode ? [] : $fallbackPaths, $paths)));

            return $strictAssetMode ? array_values(array_unique($rankedPaths)) : $ordered;
        }

        if (empty($paths)) {
            return [];
        }

        $primaryCanonicalPaths = [];
        $canonicalPaths = [];
        foreach ((array) data_get($assetIdentity, 'slots', []) as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            foreach ((array) data_get($slot, 'identity_pack.canonical_assets', data_get($slot, 'canonical_assets', [])) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $path = trim((string) ($asset['path'] ?? ''));
                if ($path !== '') {
                    $canonicalPaths[] = $path;
                    if ((bool) ($asset['is_primary'] ?? false)) {
                        $primaryCanonicalPaths[] = $path;
                    }
                }
            }
            $canonicalAssetPath = trim((string) ($slot['canonical_asset_path'] ?? ''));
            if ($canonicalAssetPath !== '') {
                $canonicalPaths[] = $canonicalAssetPath;
                $primaryCanonicalPaths[] = $canonicalAssetPath;
            }
        }

        foreach ($this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', [])) as $row) {
            foreach ((array) data_get($row, 'identity_pack.canonical_assets', []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $path = trim((string) ($asset['path'] ?? ''));
                if ($path !== '') {
                    $canonicalPaths[] = $path;
                    if ((bool) ($asset['is_primary'] ?? false)) {
                        $primaryCanonicalPaths[] = $path;
                    }
                }
            }
            $canonicalAssetPath = trim((string) ($row['canonical_asset_path'] ?? ''));
            if ($canonicalAssetPath !== '') {
                $canonicalPaths[] = $canonicalAssetPath;
                $primaryCanonicalPaths[] = $canonicalAssetPath;
            }
        }

        $primaryCanonicalPaths = array_values(array_unique(array_filter($primaryCanonicalPaths)));
        $canonicalPaths = array_values(array_unique(array_filter($canonicalPaths)));
        if (empty($canonicalPaths)) {
            return $paths;
        }

        $selectedPrimary = array_values(array_filter($primaryCanonicalPaths, fn ($path) => in_array($path, $paths, true)));
        $selectedCanonical = array_values(array_filter($canonicalPaths, fn ($path) => in_array($path, $paths, true)));
        if (empty($selectedCanonical)) {
            return $paths;
        }

        if ($strictAssetMode) {
            return !empty($selectedPrimary) ? $selectedPrimary : $selectedCanonical;
        }

        $selectedSecondary = array_values(array_filter(
            $selectedCanonical,
            fn ($path) => !in_array($path, $selectedPrimary, true)
        ));
        $remaining = array_values(array_filter($paths, fn ($path) => !in_array($path, $selectedCanonical, true)));

        return array_values(array_unique(array_merge($selectedPrimary, $selectedSecondary, $remaining)));
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $assetIdentity
     * @return array<int, string>
     */
    private function identityProtectedReferencePaths(array $assetVariables, array $assetIdentity): array
    {
        $protected = [];
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));

        foreach (['presenter', 'product', 'place'] as $slot) {
            $slotRow = data_get($assetIdentity, 'slots.' . $slot);
            if (is_array($slotRow)) {
                $protected = array_merge($protected, $this->rowCanonicalReferencePaths($slotRow));
                continue;
            }

            foreach ($resolved as $row) {
                if ($this->referenceProtectionSlotForRow($row) !== $slot) {
                    continue;
                }

                $protected = array_merge($protected, $this->rowCanonicalReferencePaths($row));
                break;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($path) => trim((string) $path),
            $protected
        ))));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function rowCanonicalReferencePaths(array $row): array
    {
        $paths = [];

        foreach ((array) data_get($row, 'identity_pack.canonical_assets', data_get($row, 'canonical_assets', [])) as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $path = trim((string) ($asset['path'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        $canonicalAssetPath = trim((string) ($row['canonical_asset_path'] ?? ''));
        if ($canonicalAssetPath !== '') {
            array_unshift($paths, $canonicalAssetPath);
        }

        foreach ((array) ($row['asset_paths'] ?? []) as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function referenceProtectionSlotForRow(array $row): string
    {
        $kind = Str::lower(trim((string) ($row['kind'] ?? 'custom')));
        $role = Str::lower(trim((string) ($row['asset_role'] ?? '')));

        if ($kind === 'person' || in_array($role, ['presenter', 'host', 'speaker'], true)) {
            return 'presenter';
        }

        if ($kind === 'location' || in_array($role, ['place', 'office', 'location', 'store', 'showroom'], true)) {
            return 'place';
        }

        if ($kind === 'product' || in_array($role, ['hero_product', 'product', 'sku'], true) || $this->rowLooksProductLike($row)) {
            return 'product';
        }

        return '';
    }

    public function targetVideoSecondsForFormat(ContentItem $item): string
    {
        $format = strtolower(trim((string) ($item->format ?? '')));
        if ($format === 'reel') {
            return '20';
        }

        $seconds = trim((string) (config('openai.video_seconds') ?: '8'));
        if (!in_array($seconds, ['4', '8', '12'], true)) {
            $seconds = '8';
        }

        if ($format === 'story' && $seconds === '12') {
            return '8';
        }

        return $seconds;
    }

    public function targetVideoSizeForFormat(ContentItem $item): string
    {
        $configured = trim((string) (config('openai.video_size') ?: ''));
        if (in_array($configured, ['720x1280', '1280x720', '1080x1920'], true)) {
            return $configured;
        }

        $format = strtolower(trim((string) ($item->format ?? '')));
        if (in_array($format, ['reel', 'story'], true)) {
            return '720x1280';
        }

        return '1280x720';
    }

    public function buildVideoReferenceImage(string $baseImageAbs, string $logoAbs, string $logoMode = 'background'): ?string
    {
        $baseInfo = @getimagesize($baseImageAbs);
        $logoInfo = @getimagesize($logoAbs);
        if (!is_array($baseInfo) || !isset($baseInfo['mime']) || !is_array($logoInfo) || !isset($logoInfo['mime'])) {
            return null;
        }

        $target = $this->loadRasterImage($baseImageAbs, (string) $baseInfo['mime']);
        $logo = $this->loadRasterImage($logoAbs, (string) $logoInfo['mime']);
        if (!$target || !$logo) {
            if (is_resource($target) || $target instanceof \GdImage) {
                imagedestroy($target);
            }
            if (is_resource($logo) || $logo instanceof \GdImage) {
                imagedestroy($logo);
            }
            return null;
        }

        $tw = imagesx($target);
        $th = imagesy($target);
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        if ($tw < 10 || $th < 10 || $lw < 2 || $lh < 2) {
            imagedestroy($target);
            imagedestroy($logo);
            return null;
        }

        if ($logoMode === 'background') {
            $maxLogoW = max(200, (int) round($tw * 0.62));
            $maxLogoH = max(200, (int) round($th * 0.62));
            $opacity = 0.18;
            $x = (int) round(($tw - min($maxLogoW, $lw)) / 2);
            $y = (int) round(($th - min($maxLogoH, $lh)) / 2);
        } else {
            $maxLogoW = max(90, (int) round($tw * 0.22));
            $maxLogoH = max(90, (int) round($th * 0.22));
            $opacity = 0.92;
            $margin = max(12, (int) round(min($tw, $th) * 0.03));
            $x = $tw - min($maxLogoW, $lw) - $margin;
            $y = $th - min($maxLogoH, $lh) - $margin;
        }

        $scale = min($maxLogoW / $lw, $maxLogoH / $lh, 1.0);
        $newW = max(1, (int) round($lw * $scale));
        $newH = max(1, (int) round($lh * $scale));

        $logoResized = imagecreatetruecolor($newW, $newH);
        imagealphablending($logoResized, false);
        imagesavealpha($logoResized, true);
        $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
        imagefilledrectangle($logoResized, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $newW, $newH, $lw, $lh);
        $this->applyOpacity($logoResized, $opacity);

        imagealphablending($target, true);
        imagesavealpha($target, true);
        $x = (int) max(0, min($tw - $newW, $x));
        $y = (int) max(0, min($th - $newH, $y));
        imagecopy($target, $logoResized, $x, $y, 0, 0, $newW, $newH);

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-ref-' . Str::uuid()->toString() . '.png';
        $saved = @imagepng($target, $tmpPath, 8);

        imagedestroy($logoResized);
        imagedestroy($logo);
        imagedestroy($target);

        if (!$saved) {
            return null;
        }

        return $tmpPath;
    }

    public function buildVideoReferenceCollage(array $imageAbsPaths): ?string
    {
        $paths = array_values(array_filter($imageAbsPaths, fn ($p) => is_string($p) && $p !== '' && is_file($p)));
        if (empty($paths)) {
            return null;
        }

        $paths = array_slice($paths, 0, 4);
        $count = count($paths);
        $cols = $count === 1 ? 1 : 2;
        $rows = $count <= 2 ? 1 : 2;

        $cellSize = 640;
        $canvasW = $cols * $cellSize;
        $canvasH = $rows * $cellSize;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        $bg = imagecolorallocate($canvas, 10, 14, 22);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $bg);

        foreach ($paths as $idx => $abs) {
            $info = @getimagesize($abs);
            if (!is_array($info) || !isset($info['mime'])) {
                continue;
            }

            $img = $this->loadRasterImage($abs, (string) $info['mime']);
            if (!$img) {
                continue;
            }

            $srcW = imagesx($img);
            $srcH = imagesy($img);
            if ($srcW < 1 || $srcH < 1) {
                imagedestroy($img);
                continue;
            }

            $col = $idx % $cols;
            $row = (int) floor($idx / $cols);
            $cellX = $col * $cellSize;
            $cellY = $row * $cellSize;

            $scale = max($cellSize / $srcW, $cellSize / $srcH);
            $dstW = (int) round($srcW * $scale);
            $dstH = (int) round($srcH * $scale);
            $dstX = $cellX + (int) round(($cellSize - $dstW) / 2);
            $dstY = $cellY + (int) round(($cellSize - $dstH) / 2);

            imagecopyresampled($canvas, $img, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($img);
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-ref-collage-' . Str::uuid()->toString() . '.png';
        $saved = @imagepng($canvas, $tmpPath, 8);
        imagedestroy($canvas);

        if (!$saved) {
            return null;
        }

        return $tmpPath;
    }

    /**
     * Crea un'immagine "scene-lock" che integra tutti i riferimenti espliciti
     * prima della generazione video, con validazione visuale.
     *
     * @param  array<int, string>  $referenceAbsPaths
     * @return array<string, mixed>|null
     */
    public function buildLockedVideoSceneReference(
        OpenAiService $openAi,
        NanoBananaService $nanoBanana,
        string $brief,
        string $prompt,
        array $referenceAbsPaths,
        array $assetVariables
    ): ?array {
        $refs = array_values(array_filter(
            array_slice($referenceAbsPaths, 0, 4),
            fn ($v) => is_string($v) && $v !== '' && is_file($v)
        ));

        if (empty($refs)) {
            return null;
        }

        $best = null;
        $bestScore = null;
        $missingHint = [];
        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $composePrompt = 'Crea una singola immagine di scena che integri TUTTI i soggetti principali presenti nelle immagini di riferimento.';
            $composePrompt .= ' Non omettere nessun soggetto richiesto.';
            $composePrompt .= ' Mantieni volti, persone, oggetti e veicoli riconoscibili e coerenti con i riferimenti.';
            $composePrompt .= ' Fai una vera fusione narrativa e visiva in un unica scena plausibile: niente collage, niente split-screen, niente mosaico, niente overlay meccanico.';
            $composePrompt .= ' Unifica luce, prospettiva, styling e ambientazione come se fosse un solo scatto strategico.';
            $composePrompt .= ' ' . $this->locationEnvelopePreservationInstruction($assetVariables, []);
            $composePrompt .= ' Niente testo, niente watermark, niente loghi inventati.';
            $composePrompt .= ' Brief: ' . Str::limit(trim($brief !== '' ? $brief : $prompt), 500, '');
            $composePrompt .= ' Direzione creativa: ' . Str::limit(trim($prompt), 380, '');

            if (!empty($missingHint)) {
                $composePrompt .= ' Nel tentativo precedente mancavano alcuni soggetti: riferimenti #' . implode(', #', $missingHint) . '.';
                $composePrompt .= ' In questo tentativo devono essere visibili tutti i soggetti mancanti.';
            }

            try {
                $img = $this->generateImageEditWithProvider(
                    provider: 'nanobanana',
                    prompt: $composePrompt,
                    editPaths: $refs,
                    openAi: $openAi,
                    nanoBanana: $nanoBanana
                );
                $bytes = base64_decode((string) ($img['b64'] ?? ''), true);
                if (!is_string($bytes) || $bytes === '') {
                    continue;
                }

                $tmpDir = storage_path('app/tmp');
                if (!is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0775, true);
                }
                $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-scene-lock-' . Str::uuid()->toString() . '.png';
                @file_put_contents($tmpPath, $bytes);
                if (!is_file($tmpPath)) {
                    continue;
                }

                $validation = $openAi->validateVideoFrameWithReferences(
                    brief: $brief !== '' ? $brief : $prompt,
                    frameAbsolutePath: $tmpPath,
                    referenceAbsolutePaths: $refs
                );

                $allPresent = (bool) data_get($validation, 'all_present', false);
                $confidence = (float) data_get($validation, 'confidence', 0.0);
                $missing = array_values(array_unique(array_filter(array_map(
                    fn ($v) => (int) $v,
                    (array) data_get($validation, 'missing_indexes', [])
                ), fn ($v) => $v > 0)));
                $missingHint = $missing;

                $score = ($allPresent ? 0 : (count($missing) * 100 + 10)) - (int) round($confidence * 10);

                if ($best === null || $bestScore === null || $score < $bestScore) {
                    if (is_array($best) && !empty($best['abs']) && is_string($best['abs']) && is_file($best['abs'])) {
                        @unlink($best['abs']);
                    }
                    $best = [
                        'abs' => $tmpPath,
                        'all_present' => $allPresent,
                        'validation' => $validation,
                        'attempts' => $attempt + 1,
                    ];
                    $bestScore = $score;
                } else {
                    @unlink($tmpPath);
                }

                if ($allPresent) {
                    break;
                }
            } catch (Throwable $e) {
                Log::info('buildLockedVideoSceneReference skipped attempt', [
                    'attempt' => $attempt + 1,
                    'error' => Str::limit($e->getMessage(), 180, ''),
                ]);
            }
        }

        return $best;
    }

    public function detectVideoExtensionFromBytes(string $bytes): string
    {
        if (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }

        return 'mp4';
    }

    public function detectImageExtensionFromBytes(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'png';
        }

        if (str_starts_with($bytes, "RIFF") && str_contains(substr($bytes, 0, 16), "WEBP")) {
            return 'webp';
        }

        return 'jpg';
    }

    public function prepareVideoReferenceForSize(string $sourceAbs, string $size): ?string
    {
        if (!is_file($sourceAbs)) {
            return null;
        }

        [$targetW, $targetH] = $this->parseVideoSize($size);
        if ($targetW < 16 || $targetH < 16) {
            return null;
        }

        $info = @getimagesize($sourceAbs);
        if (!is_array($info) || !isset($info['mime'])) {
            return null;
        }

        $source = $this->loadRasterImage($sourceAbs, (string) $info['mime']);
        if (!$source) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($source);
            return null;
        }

        if ($srcW === $targetW && $srcH === $targetH) {
            imagedestroy($source);
            return null;
        }

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, true);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $black);

        // cover crop to match exact size requested by video API
        $scale = max($targetW / $srcW, $targetH / $srcH);
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);
        $dstX = (int) round(($targetW - $newW) / 2);
        $dstY = (int) round(($targetH - $newH) / 2);
        imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'video-ref-sized-' . Str::uuid()->toString() . '.png';
        $saved = @imagepng($canvas, $tmpPath, 8);

        imagedestroy($canvas);
        imagedestroy($source);

        if (!$saved) {
            return null;
        }

        return $tmpPath;
    }

    /**
     * @return array{0:int,1:int}
     */
    public function parseVideoSize(string $size): array
    {
        $size = trim(strtolower($size));
        if (!preg_match('/^(\d{2,5})x(\d{2,5})$/', $size, $m)) {
            return [720, 1280];
        }
        return [(int) $m[1], (int) $m[2]];
    }

    public function applyBrandLogoOverlay(ContentItem $item, array $strategy, array $meta): ?array
    {
        try {
            $imageSource = (string) data_get($meta, 'image_generation.source', '');
            if (!$this->shouldApplyLogoOverlay($item, $imageSource, $meta)) {
                return ['applied' => false, 'reason' => 'overlay_policy_skip'];
            }

            $imagePath = trim((string) $item->ai_image_path);
            if ($imagePath === '') {
                return ['applied' => false, 'reason' => 'missing_image'];
            }

            $logoPath = $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id);
            if ($logoPath === null) {
                return ['applied' => false, 'reason' => 'missing_logo_asset'];
            }

            $disk = Storage::disk('public');
            if (!$disk->exists($imagePath)) {
                return ['applied' => false, 'reason' => 'image_not_found'];
            }
            if (!$disk->exists($logoPath)) {
                return ['applied' => false, 'reason' => 'logo_not_found'];
            }

            $imageAbs = $disk->path($imagePath);
            $logoAbs = $disk->path($logoPath);
            $imgInfo = @getimagesize($imageAbs);
            $logoInfo = @getimagesize($logoAbs);

            if (!is_array($imgInfo) || !isset($imgInfo['mime'])) {
                return ['applied' => false, 'reason' => 'invalid_target_image'];
            }
            if (!is_array($logoInfo) || !isset($logoInfo['mime'])) {
                return ['applied' => false, 'reason' => 'invalid_logo_image_or_svg'];
            }

            $target = $this->loadRasterImage($imageAbs, (string) $imgInfo['mime']);
            $logo = $this->loadRasterImage($logoAbs, (string) $logoInfo['mime']);
            if (!$target || !$logo) {
                if (is_resource($target) || $target instanceof \GdImage) {
                    imagedestroy($target);
                }
                if (is_resource($logo) || $logo instanceof \GdImage) {
                    imagedestroy($logo);
                }
                return ['applied' => false, 'reason' => 'unsupported_image_format'];
            }

            $tw = imagesx($target);
            $th = imagesy($target);
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            if ($tw < 10 || $th < 10 || $lw < 2 || $lh < 2) {
                imagedestroy($target);
                imagedestroy($logo);
                return ['applied' => false, 'reason' => 'invalid_dimensions'];
            }

            $overlayMode = (string) data_get($meta, 'logo_runtime.mode', 'corner');
            if ($overlayMode === 'background') {
                $maxLogoW = max(200, (int) round($tw * 0.62));
                $maxLogoH = max(200, (int) round($th * 0.62));
            } else {
                $maxLogoW = max(90, (int) round($tw * 0.2));
                $maxLogoH = max(90, (int) round($th * 0.2));
            }
            $scale = min($maxLogoW / $lw, $maxLogoH / $lh, 1.0);
            $newW = max(1, (int) round($lw * $scale));
            $newH = max(1, (int) round($lh * $scale));

            $logoResized = imagecreatetruecolor($newW, $newH);
            imagealphablending($logoResized, false);
            imagesavealpha($logoResized, true);
            $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
            imagefilledrectangle($logoResized, 0, 0, $newW, $newH, $transparent);
            imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $newW, $newH, $lw, $lh);

            $style = $this->overlayStyleForItem($item, $tw, $th, $newW, $newH, $overlayMode);
            if (($style['opacity'] ?? 1.0) < 1.0) {
                $this->applyOpacity($logoResized, (float) $style['opacity']);
            }

            $angle = (float) ($style['angle'] ?? 0.0);
            if (abs($angle) > 0.001) {
                $rotated = imagerotate($logoResized, $angle, imagecolorallocatealpha($logoResized, 0, 0, 0, 127));
                if ($rotated !== false) {
                    imagesavealpha($rotated, true);
                    imagedestroy($logoResized);
                    $logoResized = $rotated;
                    $newW = imagesx($logoResized);
                    $newH = imagesy($logoResized);
                }
            }

            imagealphablending($target, true);
            imagesavealpha($target, true);

            $x = (int) max(0, min($tw - $newW, (int) ($style['x'] ?? ($tw - $newW))));
            $y = (int) max(0, min($th - $newH, (int) ($style['y'] ?? ($th - $newH))));
            imagecopy($target, $logoResized, $x, $y, 0, 0, $newW, $newH);

            $saved = $this->saveRasterImage($target, $imageAbs, (string) $imgInfo['mime']);

            imagedestroy($logoResized);
            imagedestroy($target);
            imagedestroy($logo);

            if (!$saved) {
                return ['applied' => false, 'reason' => 'save_failed'];
            }

            return [
                'applied' => true,
                'logo_path' => $logoPath,
                'position' => $style['name'] ?? 'overlay',
                'size_ratio' => round($newW / max(1, $tw), 4),
                'applied_at' => now()->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            return [
                'applied' => false,
                'reason' => 'overlay_exception',
                'error' => Str::limit($e->getMessage(), 160, ''),
            ];
        }
    }

    public function shouldApplyLogoOverlay(ContentItem $item, string $imageSource, array $meta = []): bool
    {
        return (bool) data_get($meta, 'logo_runtime.force', false);
    }

    public function overlayStyleForItem(ContentItem $item, int $tw, int $th, int $w, int $h, string $mode = 'corner'): array
    {
        if ($mode === 'background') {
            return [
                'name' => 'center-background',
                'x' => (int) round(($tw - $w) / 2),
                'y' => (int) round(($th - $h) / 2),
                'angle' => 0,
                'opacity' => 0.18,
            ];
        }

        $m = max(12, (int) round(min($tw, $th) * 0.03));
        $seed = ($item->id + $this->positionInPlan($item)) % 4;

        return match ($seed) {
            0 => [
                'name' => 'corner-bottom-right',
                'x' => $tw - $w - $m,
                'y' => $th - $h - $m,
                'angle' => 0,
                'opacity' => 0.95,
            ],
            1 => [
                'name' => 'corner-top-left',
                'x' => $m,
                'y' => $m,
                'angle' => 0,
                'opacity' => 0.9,
            ],
            2 => [
                'name' => 'corner-top-right',
                'x' => $tw - $w - $m,
                'y' => $m,
                'angle' => 0,
                'opacity' => 0.9,
            ],
            default => [
                'name' => 'corner-bottom-left',
                'x' => $m,
                'y' => $th - $h - $m,
                'angle' => 0,
                'opacity' => 0.92,
            ],
        };
    }

    public function applyOpacity($img, float $opacity): void
    {
        $opacity = max(0.15, min(1.0, $opacity));
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $alphaFloat = 1.0 - ($a / 127.0);
                $alphaFloat *= $opacity;
                $newA = 127 - (int) round(127 * $alphaFloat);
                $newA = max(0, min(127, $newA));

                $color = imagecolorallocatealpha($img, $r, $g, $b, $newA);
                imagesetpixel($img, $x, $y, $color);
            }
        }
    }

    public function resolveLogoRuntime(ContentItem $item, array $strategy, array $meta, ?string $selectedBrandImageAbs): array
    {
        $brief = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        $requested = $this->briefRequestsLogoAsset($brief);
        $mode = $this->briefWantsBackgroundLogo($brief) ? 'background' : 'scene';
        $variant = $this->briefRequestedLogoVariant($brief);

        $candidates = $this->loadRasterLogoCandidates($meta, (int) $item->tenant_id);
        $selected = null;
        $reason = $requested ? 'logo_requested_but_not_found' : 'default_logo_not_found';
        $targetBrightness = $this->estimateImageBrightness($selectedBrandImageAbs);

        if (!$requested) {
            $defaultPath = trim((string) $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id));
            if ($defaultPath !== '') {
                foreach ($candidates as $candidate) {
                    if (($candidate['path'] ?? null) === $defaultPath) {
                        $selected = $candidate;
                        $reason = 'default_logo_path';
                        break;
                    }
                }
            }
            if ($selected === null && !empty($candidates)) {
                $selected = $candidates[0];
                $reason = 'default_latest_logo';
            }
        } elseif (!empty($candidates)) {
            $desired = $variant;
            if ($desired === 'auto') {
                if (is_float($targetBrightness)) {
                    $desired = $targetBrightness >= 0.56 ? 'dark' : 'light';
                    $reason = 'auto_logo_contrast_' . $desired;
                } else {
                    $desired = 'auto';
                    $reason = 'auto_logo_default_latest';
                }
            } else {
                $reason = 'brief_logo_variant_' . $desired;
            }

            if (in_array($desired, ['light', 'dark'], true)) {
                foreach ($candidates as $candidate) {
                    if (($candidate['tone'] ?? null) === $desired) {
                        $selected = $candidate;
                        $reason .= '_name_match';
                        break;
                    }
                }

                if ($selected === null) {
                    $ranked = $candidates;
                    usort($ranked, function (array $a, array $b) use ($desired) {
                        $ba = $a['brightness'] ?? null;
                        $bb = $b['brightness'] ?? null;
                        if (!is_float($ba) && !is_float($bb)) {
                            return 0;
                        }
                        if (!is_float($ba)) {
                            return 1;
                        }
                        if (!is_float($bb)) {
                            return -1;
                        }
                        if ($ba === $bb) {
                            return 0;
                        }
                        if ($desired === 'light') {
                            return $ba < $bb ? 1 : -1;
                        }
                        return $ba < $bb ? -1 : 1;
                    });
                    $selected = $ranked[0] ?? null;
                    if ($selected !== null) {
                        $reason .= '_brightness_match';
                    }
                }
            }

            if ($selected === null) {
                $selected = $candidates[0];
                $reason = 'logo_requested_default_latest';
            }
        }

        if ($selected === null) {
            $fallbackPath = trim((string) $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id));
            if ($fallbackPath !== '') {
                $disk = Storage::disk('public');
                if ($disk->exists($fallbackPath)) {
                    $fallbackAbs = $disk->path($fallbackPath);
                    $mime = strtolower((string) (mime_content_type($fallbackAbs) ?: ''));
                    if (str_starts_with($mime, 'image/') && !str_contains($mime, 'svg')) {
                        $selected = [
                            'path' => $fallbackPath,
                            'abs' => $fallbackAbs,
                            'tone' => null,
                            'brightness' => null,
                        ];
                        $reason = 'logo_fallback_from_strategy';
                    }
                }
            }
        }

        return [
            'requested' => $requested,
            'mode' => $mode,
            'variant' => $variant,
            'path' => is_array($selected) ? (string) ($selected['path'] ?? '') : null,
            'abs' => is_array($selected) ? (string) ($selected['abs'] ?? '') : null,
            'reason' => $reason,
        ];
    }

    public function loadRasterLogoCandidates(array $meta, int $tenantId): array
    {
        $rows = [];

        foreach ((array) data_get($meta, 'brand_assets', []) as $asset) {
            if (!is_array($asset) || (($asset['kind'] ?? null) !== 'logo')) {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'original_name' => trim((string) ($asset['original_name'] ?? '')),
            ];
        }

        $db = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'logo')
            ->orderByDesc('id')
            ->limit(24)
            ->get(['path', 'original_name']);

        foreach ($db as $row) {
            $path = trim((string) ($row->path ?? ''));
            if ($path === '') {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'original_name' => trim((string) ($row->original_name ?? '')),
            ];
        }

        $disk = Storage::disk('public');
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === '' || isset($seen[$path]) || !$disk->exists($path)) {
                continue;
            }
            $seen[$path] = true;
            $abs = $disk->path($path);
            $mime = strtolower((string) (mime_content_type($abs) ?: ''));
            if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
                continue;
            }
            $name = trim((string) ($row['original_name'] ?? basename($path)));
            $tone = $this->detectLogoToneHint($name . ' ' . basename($path));
            $brightness = $this->estimateImageBrightness($abs);
            $out[] = [
                'path' => $path,
                'abs' => $abs,
                'tone' => $tone,
                'brightness' => $brightness,
            ];
        }

        return $out;
    }

    public function detectLogoToneHint(string $text): ?string
    {
        $v = $this->normalizeText($text);
        if ($v === '') {
            return null;
        }

        $light = ['chiaro', 'bianco', 'white', 'light', 'clear', 'invertito', 'inverted'];
        $dark = ['scuro', 'nero', 'black', 'dark'];

        foreach ($light as $needle) {
            if (str_contains($v, $needle)) {
                return 'light';
            }
        }
        foreach ($dark as $needle) {
            if (str_contains($v, $needle)) {
                return 'dark';
            }
        }

        return null;
    }

    public function briefRequestsLogoAsset(string $briefNormalized): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        foreach (['logo', 'logotipo', 'marchio', 'brandmark', 'brand mark'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function briefWantsBackgroundLogo(string $briefNormalized): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        foreach (['dietro', 'sfondo', 'background', 'watermark', 'filigrana'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function briefRequestedLogoVariant(string $briefNormalized): string
    {
        if ($briefNormalized === '') {
            return 'auto';
        }

        $hasLight = false;
        foreach (['chiaro', 'bianco', 'white', 'light'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                $hasLight = true;
                break;
            }
        }

        $hasDark = false;
        foreach (['scuro', 'nero', 'black', 'dark'] as $needle) {
            if (str_contains($briefNormalized, $needle)) {
                $hasDark = true;
                break;
            }
        }

        if ($hasLight && !$hasDark) {
            return 'light';
        }
        if ($hasDark && !$hasLight) {
            return 'dark';
        }

        return 'auto';
    }

    public function estimateImageBrightness(?string $absolutePath): ?float
    {
        if (!is_string($absolutePath) || trim($absolutePath) === '' || !is_file($absolutePath)) {
            return null;
        }

        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }

        $img = $this->loadRasterImage($absolutePath, $mime);
        if (!$img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);
            return null;
        }

        $step = max(1, (int) floor(max($w, $h) / 64));
        $sum = 0.0;
        $count = 0;

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a >= 126) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $alpha = 1.0 - ($a / 127.0);
                $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255.0;
                $sum += ($lum * $alpha);
                $count++;
            }
        }

        imagedestroy($img);

        if ($count < 1) {
            return null;
        }

        return $sum / $count;
    }

    public function resolveLogoPath(array $strategy, array $meta, int $tenantId): ?string
    {
        $forcedLogoPath = trim((string) data_get($meta, 'logo_runtime.path', ''));
        if ($forcedLogoPath !== '') {
            return $forcedLogoPath;
        }

        $logoPath = trim((string) data_get($strategy, 'brand_references.logo_path', ''));
        if ($logoPath !== '') {
            return $logoPath;
        }

        $assets = (array) data_get($meta, 'brand_assets', []);
        foreach ($assets as $asset) {
            if (($asset['kind'] ?? null) === 'logo' && !empty($asset['path'])) {
                return (string) $asset['path'];
            }
        }

        $dbLogo = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'logo')
            ->orderByDesc('id')
            ->first(['path']);

        if ($dbLogo && !empty($dbLogo->path)) {
            return (string) $dbLogo->path;
        }

        return null;
    }

    public function resolveRasterLogoAbsolutePath(array $strategy, array $meta, int $tenantId): ?string
    {
        $logoRel = $this->resolveLogoPath($strategy, $meta, $tenantId);
        if (!$logoRel) {
            return null;
        }
        $disk = Storage::disk('public');
        if (!$disk->exists($logoRel)) {
            return null;
        }
        $abs = $disk->path($logoRel);
        $mime = strtolower((string) (mime_content_type($abs) ?: ''));
        if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }
        return $abs;
    }

    public function shouldEmbedLogoInScene(ContentItem $item, ?string $selectedBrandImageAbs, ?string $logoAbs): bool
    {
        if (!$selectedBrandImageAbs || !$logoAbs) {
            return false;
        }
        // Saltuario e deterministico (~1 ogni 3 post).
        return ($this->positionInPlan($item) % 3) === 0;
    }

    public function loadRasterImage(string $path, string $mime)
    {
        return match (strtolower($mime)) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
    }

    public function saveRasterImage($image, string $path, string $mime): bool
    {
        return match (strtolower($mime)) {
            'image/png' => @imagepng($image, $path, 9),
            'image/jpeg', 'image/jpg' => @imagejpeg($image, $path, 92),
            'image/webp' => function_exists('imagewebp') ? @imagewebp($image, $path, 90) : false,
            default => false,
        };
    }

    public function maxTextSimilarity(string $text, array $candidates): float
    {
        $text = $this->normalizeText($text);
        if ($text === '' || empty($candidates)) {
            return 0.0;
        }

        $max = 0.0;
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeText((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $score = $this->textSimilarityScore($text, $candidate);
            if ($score > $max) {
                $max = $score;
            }
        }
        return $max;
    }

    public function closestText(string $text, array $candidates): ?string
    {
        $base = $this->normalizeText($text);
        $best = null;
        $bestScore = -1.0;
        foreach ($candidates as $candidate) {
            $c = $this->normalizeText((string) $candidate);
            if ($c === '') {
                continue;
            }
            $score = $this->textSimilarityScore($base, $c);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $candidate;
            }
        }
        return $best;
    }

    public function textSimilarityScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $ta = array_values(array_filter(explode(' ', $a)));
        $tb = array_values(array_filter(explode(' ', $b)));
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }

        $ia = array_count_values($ta);
        $ib = array_count_values($tb);
        $shared = 0;
        foreach ($ia as $token => $count) {
            if (isset($ib[$token])) {
                $shared += min($count, $ib[$token]);
            }
        }
        $union = max(1, array_sum($ia) + array_sum($ib) - $shared);
        $jaccard = $shared / $union;

        similar_text($a, $b, $percent);
        $charScore = $percent / 100.0;

        return min(1.0, ($jaccard * 0.65) + ($charScore * 0.35));
    }

    public function normalizeText(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveAssetVariableContext(int $tenantId, array $meta, array $strategy): array
    {
        $metaPayload = (array) data_get($meta, 'asset_variables', []);

        $catalog = $this->normalizeAssetVariableRows((array) data_get($metaPayload, 'catalog', []));
        if (empty($catalog)) {
            $catalog = $this->normalizeAssetVariableRows((array) data_get($meta, 'asset_variables_catalog', []));
        }
        if (empty($catalog)) {
            $catalog = $this->normalizeAssetVariableRows((array) data_get($strategy, 'brand_references.asset_variables', []));
        }
        $catalog = $this->mergeLiveAssetVariableCatalog($catalog, $this->loadAssetVariableCatalogFromDb($tenantId));

        $requestedIds = collect((array) data_get($metaPayload, 'requested_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $detectedIds = collect((array) data_get($metaPayload, 'detected_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $recognizedTerms = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            (array) data_get($metaPayload, 'recognized_terms', [])
        ))));

        $resolved = $this->refreshResolvedRowsFromCatalog(
            $this->normalizeAssetVariableRows((array) data_get($metaPayload, 'resolved', [])),
            $catalog
        );

        if (empty($resolved) && !empty($catalog) && (!empty($requestedIds) || !empty($detectedIds))) {
            $catalogById = collect($catalog)->keyBy(fn ($row) => (int) ($row['id'] ?? 0));
            $resolved = collect(array_merge($requestedIds, $detectedIds))
                ->map(fn (int $id) => $catalogById->get($id))
                ->filter()
                ->values()
                ->all();
        }

        $brief = $this->normalizeText((string) data_get($meta, 'manual_brief', ''));
        if ($brief !== '' && !empty($catalog)) {
            foreach ($catalog as $row) {
                if (!$this->assetVariableMatchesBrief($brief, $row)) {
                    continue;
                }

                $resolved[] = $row;
                $detectedId = (int) ($row['id'] ?? 0);
                if ($detectedId > 0) {
                    $detectedIds[] = $detectedId;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $recognizedTerms[] = $name;
                }
            }
        }

        $resolved = $this->normalizeAssetVariableRows($resolved);

        $resolvedIds = collect($resolved)
            ->map(fn ($row) => (int) ($row['id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $resolvedAssetIds = collect($resolved)
            ->flatMap(fn ($row) => (array) ($row['asset_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $resolvedAssetPaths = collect($resolved)
            ->flatMap(fn ($row) => (array) ($row['asset_paths'] ?? []))
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn (string $path) => $path !== '')
            ->unique()
            ->values()
            ->all();

        $selectionMode = (string) data_get($metaPayload, 'selection_mode', '');
        if ($selectionMode === '' || $selectionMode === 'none') {
            $selectionMode = $this->assetVariableSelectionMode(
                !empty($requestedIds),
                !empty($detectedIds)
            );
        }

        return [
            'catalog' => $catalog,
            'requested_ids' => array_values(array_unique($requestedIds)),
            'detected_ids' => array_values(array_unique($detectedIds)),
            'resolved_ids' => $resolvedIds,
            'resolved_asset_ids' => $resolvedAssetIds,
            'resolved_asset_paths' => $resolvedAssetPaths,
            'resolved' => $resolved,
            'recognized_terms' => array_values(array_unique(array_filter($recognizedTerms))),
            'selection_mode' => $selectionMode,
        ];
    }

    /**
     * Normalizza il payload presenter/product/place senza perdere i riferimenti risolti.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assetVariables
     * @return array<string, mixed>
     */
    public function resolveAssetIdentityContext(array $meta, array $assetVariables): array
    {
        $payload = (array) data_get($meta, 'asset_identity', []);
        $resolved = collect($this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', [])));
        $slots = [];
        $lockedElements = collect((array) data_get($payload, 'locked_elements', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();
        $allowedChanges = collect((array) data_get($payload, 'allowed_changes', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();

        foreach ((array) data_get($payload, 'slots', []) as $slot => $row) {
            if (!is_array($row)) {
                continue;
            }

            $resolvedRow = $resolved->first(fn ($variable) => (int) ($variable['id'] ?? 0) === (int) ($row['id'] ?? 0));
            $merged = is_array($resolvedRow) ? array_replace($resolvedRow, $row) : $row;
            $identityPack = app(AssetIdentityService::class)->synthesizeIdentityPackFromRow($merged);
            $merged['identity_pack'] = $identityPack;
            $merged['canonical_assets'] = (array) data_get($identityPack, 'canonical_assets', []);
            $merged['maintain_elements'] = array_values(array_filter(array_map('strval', (array) data_get($identityPack, 'invariants', []))));
            $merged['changeable_elements'] = array_values(array_filter(array_map('strval', (array) data_get($identityPack, 'transformables', []))));
            $merged['locked_elements'] = $merged['maintain_elements'];
            $merged['allowed_transforms'] = $merged['changeable_elements'];
            $merged['strictness_level'] = (string) data_get($identityPack, 'strictness_level', (string) ($merged['identity_mode'] ?? 'balanced'));
            $slots[(string) $slot] = $merged;
            $lockedElements = array_merge($lockedElements, $merged['maintain_elements']);
            $allowedChanges = array_merge($allowedChanges, $merged['changeable_elements']);
        }

        $lockedElements = array_values(array_unique(array_filter(array_map('strval', $lockedElements))));
        $allowedChanges = array_values(array_unique(array_filter(array_map('strval', $allowedChanges))));

        return [
            'slots' => $slots,
            'slot_ids' => collect((array) data_get($payload, 'slot_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all(),
            'seasonal_overlay' => trim((string) data_get($payload, 'seasonal_overlay', '')),
            'consistency_mode' => trim((string) data_get($payload, 'consistency_mode', 'balanced')),
            'locked_elements' => $lockedElements,
            'maintain_elements' => $lockedElements,
            'allowed_changes' => $allowedChanges,
            'changeable_elements' => $allowedChanges,
        ];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     */
    public function buildAssetVariablePromptHint(array $assetVariables): string
    {
        $resolved = $this->normalizeAssetVariableRows((array) data_get($assetVariables, 'resolved', []));
        if (empty($resolved)) {
            return '';
        }

        $parts = [];
        foreach (array_slice($resolved, 0, 4) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            $kind = trim((string) ($row['kind'] ?? 'custom'));
            $identityPack = is_array($row['identity_pack'] ?? null)
                ? $row['identity_pack']
                : app(AssetIdentityService::class)->synthesizeIdentityPackFromRow($row);

            $label = $name !== '' ? $name : ($slug !== '' ? '@' . $slug : 'variabile');
            if ($kind !== '') {
                $label .= ' [' . $kind . ']';
            }
            $assetRole = trim((string) ($row['asset_role'] ?? ''));
            if ($assetRole !== '') {
                $label .= ' role: ' . $assetRole;
            }
            $strictness = trim((string) data_get($identityPack, 'strictness_level', (string) ($row['identity_mode'] ?? '')));
            if ($strictness !== '') {
                $label .= ' strictness: ' . $strictness;
            }
            $threshold = isset($row['consistency_threshold']) ? (int) $row['consistency_threshold'] : 0;
            if ($threshold > 0) {
                $label .= ' soglia: ' . $threshold;
            }

            $canonicalRefs = collect((array) data_get($identityPack, 'canonical_assets', []))
                ->map(fn ($asset) => is_array($asset) ? trim((string) basename((string) ($asset['path'] ?? ''))) : '')
                ->filter(fn (string $value) => $value !== '')
                ->take(2)
                ->values()
                ->all();
            if (!empty($canonicalRefs)) {
                $label .= ' canonici: ' . implode(', ', $canonicalRefs);
            }

            $invariants = collect((array) data_get($identityPack, 'invariants', []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(3)
                ->values()
                ->all();
            if (!empty($invariants)) {
                $label .= ' mantieni: ' . implode(', ', $invariants);
            }

            $transformables = collect((array) data_get($identityPack, 'transformables', []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(3)
                ->values()
                ->all();
            if (!empty($transformables)) {
                $label .= ' puoi variare: ' . implode(', ', $transformables);
            }

            $visualTags = collect((array) data_get($identityPack, 'visual_tags', []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(2)
                ->values()
                ->all();
            if (!empty($visualTags)) {
                $label .= ' tag: ' . implode(', ', $visualTags);
            }

            $parts[] = $label;
        }

        return Str::limit(implode('; ', $parts), 640, '');
    }

    /**
     * Questo hint e piu sintetico e specifico per il singolo contenuto in costruzione.
     *
     * @param  array<string, mixed>  $assetIdentity
     */
    public function buildAssetIdentityPromptHint(array $assetIdentity): string
    {
        $slots = is_array($assetIdentity['slots'] ?? null) ? $assetIdentity['slots'] : [];
        if (empty($slots) && empty((array) ($assetIdentity['locked_elements'] ?? [])) && trim((string) ($assetIdentity['seasonal_overlay'] ?? '')) === '') {
            return '';
        }

        $parts = [];

        foreach ($slots as $slot => $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $label = $slot . ': ' . $name;
            $descriptor = trim((string) data_get($row, 'identity_pack.descriptor.summary', data_get($row, 'descriptor.summary', data_get($row, 'profile.descriptor.summary', ''))));
            if ($descriptor !== '') {
                $label .= ' (' . Str::limit($descriptor, 90, '') . ')';
            }
            $maintain = collect((array) ($row['maintain_elements'] ?? $row['locked_elements'] ?? []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(2)
                ->values()
                ->all();
            if (!empty($maintain)) {
                $label .= ' | mantieni: ' . implode(', ', $maintain);
            }
            $changeable = collect((array) ($row['changeable_elements'] ?? $row['allowed_transforms'] ?? []))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => $value !== '')
                ->take(2)
                ->values()
                ->all();
            if (!empty($changeable)) {
                $label .= ' | puoi variare: ' . implode(', ', $changeable);
            }

            $parts[] = $label;
        }

        $seasonalOverlay = trim((string) ($assetIdentity['seasonal_overlay'] ?? ''));
        if ($seasonalOverlay !== '') {
            $parts[] = 'overlay: ' . Str::limit($seasonalOverlay, 80, '');
        }

        $maintainElements = collect((array) ($assetIdentity['maintain_elements'] ?? $assetIdentity['locked_elements'] ?? []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->take(4)
            ->values()
            ->all();
        if (!empty($maintainElements)) {
            $parts[] = 'mantieni: ' . implode('; ', $maintainElements);
        }

        $changeableElements = collect((array) ($assetIdentity['changeable_elements'] ?? $assetIdentity['allowed_changes'] ?? []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->take(4)
            ->values()
            ->all();
        if (!empty($changeableElements)) {
            $parts[] = 'puoi cambiare: ' . implode(', ', $changeableElements);
        }

        $consistencyMode = trim((string) ($assetIdentity['consistency_mode'] ?? ''));
        if ($consistencyMode !== '') {
            $parts[] = 'consistency: ' . $consistencyMode;
        }

        return Str::limit(implode('; ', $parts), 680, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function loadAssetVariableCatalogFromDb(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }

        return $this->normalizeAssetVariableRows(
            app(AssetVariableService::class)->catalogForTenant($tenantId)
        );
    }

    public function mergeLiveAssetVariableCatalog(array $catalog, array $liveCatalog): array
    {
        if (empty($liveCatalog)) {
            return $catalog;
        }

        $merged = [];
        foreach ($catalog as $row) {
            $key = $this->assetVariableCatalogKey($row);
            if ($key === '') {
                continue;
            }
            $merged[$key] = $row;
        }

        foreach ($liveCatalog as $row) {
            $key = $this->assetVariableCatalogKey($row);
            if ($key === '') {
                continue;
            }
            $merged[$key] = isset($merged[$key]) && is_array($merged[$key])
                ? array_replace($merged[$key], $row)
                : $row;
        }

        return array_values($merged);
    }

    public function refreshResolvedRowsFromCatalog(array $resolved, array $catalog): array
    {
        if (empty($resolved) || empty($catalog)) {
            return $resolved;
        }

        $catalogMap = [];
        foreach ($catalog as $row) {
            $key = $this->assetVariableCatalogKey($row);
            if ($key === '') {
                continue;
            }
            $catalogMap[$key] = $row;
        }

        foreach ($resolved as $index => $row) {
            $key = $this->assetVariableCatalogKey($row);
            if ($key === '' || !isset($catalogMap[$key])) {
                continue;
            }
            $resolved[$index] = array_replace($row, $catalogMap[$key]);
        }

        return $resolved;
    }

    public function assetVariableCatalogKey(array $row): string
    {
        $id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($id > 0) {
            return 'id:' . $id;
        }

        $slug = Str::lower(trim((string) ($row['slug'] ?? '')));

        return $slug !== '' ? 'slug:' . $slug : '';
    }

    public function normalizeAssetVariableRows(array $rows): array
    {
        $out = [];
        $seen = [];
        $identityService = app(AssetIdentityService::class);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) ? (int) $row['id'] : null;
            $name = trim((string) ($row['name'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? Str::slug($name)));
            if ($name === '' && $slug === '') {
                continue;
            }

            $key = ($id && $id > 0) ? ('id:' . $id) : ('slug:' . Str::lower($slug));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $assetIds = collect((array) ($row['asset_ids'] ?? []))
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values()
                ->all();

            $assetPaths = collect((array) ($row['asset_paths'] ?? []))
                ->map(fn ($path) => trim((string) $path))
                ->filter(fn (string $path) => $path !== '')
                ->unique()
                ->values()
                ->all();

            if (empty($assetPaths) && !empty($row['assets']) && is_array($row['assets'])) {
                $assetPaths = collect((array) $row['assets'])
                    ->map(fn ($asset) => is_array($asset) ? trim((string) ($asset['path'] ?? '')) : '')
                    ->filter(fn (string $path) => $path !== '')
                    ->unique()
                    ->values()
                    ->all();
            }

            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
            $voiceAssetId = isset($row['voice_asset_id']) ? (int) $row['voice_asset_id'] : (int) data_get($profile, 'voice_reference.sample_asset_id', 0);
            $voiceAssetPath = trim((string) ($row['voice_asset_path'] ?? data_get($profile, 'voice_reference.sample_path', '')));
            $voiceAssetName = trim((string) ($row['voice_asset_name'] ?? data_get($profile, 'voice_reference.sample_name', '')));
            $voiceProvider = trim((string) ($row['voice_provider'] ?? data_get($profile, 'voice_reference.provider', '')));
            $voiceProviderVoiceId = trim((string) ($row['voice_provider_voice_id'] ?? data_get($profile, 'voice_reference.provider_voice_id', '')));
            $voiceStatus = trim((string) ($row['voice_status'] ?? data_get($profile, 'voice_reference.status', '')));

            $normalized = [
                'id' => $id && $id > 0 ? $id : null,
                'name' => $name,
                'slug' => $slug,
                'kind' => trim((string) ($row['kind'] ?? 'custom')),
                'asset_role' => trim((string) ($row['asset_role'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'canonical_asset_id' => isset($row['canonical_asset_id']) ? (int) $row['canonical_asset_id'] : null,
                'canonical_asset_path' => trim((string) ($row['canonical_asset_path'] ?? '')),
                'voice_asset_id' => $voiceAssetId > 0 ? $voiceAssetId : null,
                'voice_asset_path' => $voiceAssetPath,
                'voice_asset_name' => $voiceAssetName,
                'voice_provider' => $voiceProvider,
                'voice_provider_voice_id' => $voiceProviderVoiceId,
                'voice_status' => $voiceStatus,
                'identity_mode' => trim((string) ($row['identity_mode'] ?? 'balanced')),
                'consistency_threshold' => isset($row['consistency_threshold']) ? (int) $row['consistency_threshold'] : null,
                'profile' => $profile,
                'asset_ids' => $assetIds,
                'asset_paths' => $assetPaths,
                'assets' => is_array($row['assets'] ?? null) ? $row['assets'] : [],
                'voice_asset' => is_array($row['voice_asset'] ?? null) ? $row['voice_asset'] : null,
                'identity_pack' => is_array($row['identity_pack'] ?? null) ? $row['identity_pack'] : [],
            ];

            $identityPack = $identityService->synthesizeIdentityPackFromRow($normalized);
            $canonicalAssetPath = trim((string) ($normalized['canonical_asset_path'] ?? ''));
            if ($canonicalAssetPath === '') {
                $canonicalAssetPath = trim((string) data_get($identityPack, 'canonical_assets.0.path', ''));
            }
            if ($canonicalAssetPath !== '' && !in_array($canonicalAssetPath, $assetPaths, true)) {
                array_unshift($assetPaths, $canonicalAssetPath);
                $assetPaths = array_values(array_unique(array_filter($assetPaths)));
            }

            $normalized['canonical_asset_path'] = $canonicalAssetPath;
            $normalized['asset_paths'] = $assetPaths;
            $normalized['identity_pack'] = $identityPack;

            $out[] = $normalized;
        }

        return array_values($out);
    }
    /**
     * @param  array<string, mixed>  $row
     */
    public function assetVariableMatchesBrief(string $briefNormalized, array $row): bool
    {
        if ($briefNormalized === '') {
            return false;
        }

        $identityPack = is_array($row['identity_pack'] ?? null)
            ? $row['identity_pack']
            : app(AssetIdentityService::class)->synthesizeIdentityPackFromRow($row);

        $name = $this->normalizeText((string) ($row['name'] ?? ''));
        $slug = $this->normalizeText(str_replace('-', ' ', (string) ($row['slug'] ?? '')));
        $profileText = $this->normalizeText(implode(' ', array_filter([
            (string) ($row['asset_role'] ?? ''),
            (string) data_get($row, 'profile.role', ''),
            (string) data_get($row, 'profile.identity_summary', ''),
            (string) data_get($row, 'profile.immutable_traits', ''),
            (string) data_get($row, 'profile.descriptor.summary', ''),
            (string) data_get($row, 'profile.prompt_lock.immutable_elements', ''),
            implode(' ', (array) data_get($row, 'profile.allowed_transforms', [])),
            (string) data_get($identityPack, 'descriptor.summary', ''),
            (string) data_get($identityPack, 'descriptor.persistent_label', ''),
            implode(' ', (array) data_get($identityPack, 'invariants', [])),
            implode(' ', (array) data_get($identityPack, 'transformables', [])),
            implode(' ', (array) data_get($identityPack, 'visual_tags', [])),
            implode(' ', (array) data_get($identityPack, 'positive_examples', [])),
            implode(' ', (array) data_get($identityPack, 'negative_examples', [])),
        ])));

        if ($name !== '' && str_contains(' ' . $briefNormalized . ' ', ' ' . $name . ' ')) {
            return true;
        }

        if ($slug !== '' && str_contains(' ' . $briefNormalized . ' ', ' ' . $slug . ' ')) {
            return true;
        }

        $slugCompact = str_replace(' ', '', $slug);
        if ($slugCompact !== '' && str_contains($briefNormalized, '@' . $slugCompact)) {
            return true;
        }

        $nameCompact = str_replace(' ', '', $name);
        if ($nameCompact !== '' && str_contains($briefNormalized, '@' . $nameCompact)) {
            return true;
        }

        if ($profileText !== '' && str_contains(' ' . $briefNormalized . ' ', ' ' . $profileText . ' ')) {
            return true;
        }

        return false;
    }

    public function assetVariableSelectionMode(bool $hasRequested, bool $hasDetected): string
    {
        if ($hasRequested && $hasDetected) {
            return 'manual+brief';
        }
        if ($hasRequested) {
            return 'manual';
        }
        if ($hasDetected) {
            return 'brief';
        }
        return 'none';
    }

    public function resolveBrandImageSources(array $strategy, array $meta, int $tenantId): array
    {
        $paths = (array) data_get($strategy, 'brand_references.reference_images', []);
        $paths = array_values(array_filter(array_map('strval', $paths)));

        if (empty($paths)) {
            $assets = (array) data_get($meta, 'brand_assets', []);
            foreach ($assets as $asset) {
                if (($asset['kind'] ?? null) === 'image' && !empty($asset['path'])) {
                    $paths[] = (string) $asset['path'];
                }
            }
        }

        if (empty($paths)) {
            $dbAssets = BrandAsset::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('content_plan_id')
                ->where('kind', 'image')
                ->orderByDesc('id')
                ->limit(48)
                ->get(['path']);

            foreach ($dbAssets as $asset) {
                if (!empty($asset->path)) {
                    $paths[] = (string) $asset->path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public function decideBrandImageUsage(ContentItem $item, array $paths, ?OpenAiService $openAi = null): array
    {
        $public = Storage::disk('public');
        $valid = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !$public->exists($path)) {
                continue;
            }
            $mime = mime_content_type($public->path($path)) ?: '';
            $mime = strtolower($mime);
            if (str_starts_with($mime, 'image/') && !str_contains($mime, 'svg')) {
                $valid[] = $path;
            }
        }
        $valid = array_values(array_unique($valid));
        if (empty($valid)) {
            return ['use_brand' => false, 'path' => null, 'reason' => 'no_valid_brand_images'];
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $valid = $this->reorderValidPathsByMetaRecency($valid, $meta);
        $explicitPaths = $this->extractExplicitReferencePaths($meta, $valid);
        if (!empty($explicitPaths)) {
            return [
                'use_brand' => true,
                'path' => $explicitPaths[0],
                'paths' => $explicitPaths,
                'reason' => 'explicit_user_image_references',
                'confidence' => 1.0,
                'selected_count' => count($explicitPaths),
            ];
        }

        $preferredPath = trim((string) data_get($meta, 'image_preference.path', ''));
        if ($preferredPath !== '' && in_array($preferredPath, $valid, true)) {
            return [
                'use_brand' => true,
                'path' => $preferredPath,
                'reason' => (string) (data_get($meta, 'image_preference.reason', 'manual_preference')),
                'confidence' => (float) (data_get($meta, 'image_preference.confidence', 1.0)),
            ];
        }

        $briefDriven = $this->selectBrandImageFromBrief($item, $valid);
        if (is_array($briefDriven) && !empty($briefDriven['path'])) {
            return $briefDriven;
        }

        if ($openAi instanceof OpenAiService) {
            $visionDriven = $this->selectBrandImageByVision($item, $valid, $openAi);
            if (is_array($visionDriven) && !empty($visionDriven['path'])) {
                return $visionDriven;
            }
        }

        $position = $this->positionInPlan($item);
        $totalInPlan = $this->totalItemsInPlan($item);
        $idx = $position % count($valid);

        // Usa sempre immagini brand quando presenti, ciclano in base alla posizione del post nel piano.
        return [
            'use_brand' => true,
            'path' => $valid[$idx],
            'reason' => 'always_use_brand_cycle',
            'position' => $position,
            'total_in_plan' => $totalInPlan,
            'valid_pool' => count($valid),
        ];
    }

    public function reorderValidPathsByMetaRecency(array $validPaths, array $meta): array
    {
        if (empty($validPaths)) {
            return [];
        }
        $validLookup = array_fill_keys($validPaths, true);
        $ordered = [];

        foreach ((array) data_get($meta, 'brand_assets', []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['kind'] ?? null) !== 'image') {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '' || !isset($validLookup[$path])) {
                continue;
            }
            $ordered[] = $path;
            unset($validLookup[$path]);
        }

        foreach ($validPaths as $path) {
            if (isset($validLookup[$path])) {
                $ordered[] = $path;
                unset($validLookup[$path]);
            }
        }

        return array_values(array_unique($ordered));
    }

    public function extractExplicitReferencePaths(array $meta, array $validPaths): array
    {
        if (empty($validPaths)) {
            return [];
        }

        $validLookup = array_fill_keys($validPaths, true);
        $paths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'image_references.selected_paths', [])
        )));
        $variablePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($meta, 'asset_variables.resolved_asset_paths', [])
        )));
        if (empty($variablePaths)) {
            $variablePaths = collect((array) data_get($meta, 'asset_variables.resolved', []))
                ->flatMap(fn ($row) => is_array($row) ? (array) ($row['asset_paths'] ?? []) : [])
                ->map(fn ($path) => (string) $path)
                ->filter(fn ($path) => $path !== '')
                ->values()
                ->all();
        }
        if (!empty($variablePaths)) {
            $paths = array_values(array_unique(array_merge($paths, $variablePaths)));
        }

        if (empty($paths)) {
            return [];
        }

        $out = [];
        foreach ($paths as $path) {
            if (!isset($validLookup[$path])) {
                continue;
            }
            $out[] = $path;
            if (count($out) >= 4) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    public function selectBrandImageFromBrief(ContentItem $item, array $validPaths): ?array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $brief = trim((string) data_get($meta, 'manual_brief', ''));
        if ($brief === '') {
            return null;
        }

        $briefNorm = $this->normalizeText($brief);
        if ($briefNorm === '') {
            return null;
        }

        if (
            str_contains($briefNorm, 'ultima immagine')
            || str_contains($briefNorm, 'ultima foto')
            || str_contains($briefNorm, 'latest image')
            || str_contains($briefNorm, 'last image')
        ) {
            $latest = reset($validPaths);
            if (is_string($latest) && $latest !== '') {
                return [
                    'use_brand' => true,
                    'path' => $latest,
                    'reason' => 'brief_latest_image_hint',
                    'confidence' => 1.0,
                ];
            }
        }

        $tokens = $this->briefMeaningfulTokens($briefNorm);
        if (empty($tokens)) {
            return null;
        }

        $assets = collect((array) data_get($meta, 'brand_assets', []))
            ->filter(fn ($a) => is_array($a) && (($a['kind'] ?? null) === 'image') && !empty($a['path']))
            ->keyBy(fn ($a) => (string) ($a['path'] ?? ''));

        $bestPath = null;
        $bestScore = 0;

        foreach ($validPaths as $path) {
            $asset = $assets->get($path);
            $name = is_array($asset) ? ((string) ($asset['original_name'] ?? '')) : '';
            $hay = $this->normalizeText($name . ' ' . basename((string) $path));
            if ($hay === '') {
                continue;
            }

            $score = 0;
            foreach ($tokens as $t) {
                if (str_contains($hay, $t)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = (string) $path;
            }
        }

        if ($bestPath !== null && $bestScore >= 2) {
            return [
                'use_brand' => true,
                'path' => $bestPath,
                'reason' => 'brief_keyword_match',
                'confidence' => min(0.95, 0.45 + ($bestScore * 0.08)),
            ];
        }

        return null;
    }

    public function selectBrandImageByVision(ContentItem $item, array $validPaths, OpenAiService $openAi): ?array
    {
        if (count($validPaths) < 2) {
            return null;
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $brief = trim((string) data_get($meta, 'manual_brief', ''));
        if ($brief === '') {
            return null;
        }

        $public = Storage::disk('public');
        $assetsByPath = collect((array) data_get($meta, 'brand_assets', []))
            ->filter(fn ($a) => is_array($a) && (($a['kind'] ?? null) === 'image') && !empty($a['path']))
            ->keyBy(fn ($a) => (string) ($a['path'] ?? ''));

        $candidates = [];
        foreach (array_slice($validPaths, 0, 6) as $path) {
            if (!$public->exists($path)) {
                continue;
            }
            $asset = $assetsByPath->get($path);
            $candidates[] = [
                'path' => (string) $path,
                'original_name' => is_array($asset) ? (string) ($asset['original_name'] ?? '') : '',
                'absolute_path' => $public->path($path),
            ];
        }

        if (count($candidates) < 2) {
            return null;
        }

        $selected = $openAi->selectBestBrandImageForBrief($brief, $candidates);
        if (!is_array($selected)) {
            return null;
        }

        $selectedPath = trim((string) ($selected['path'] ?? ''));
        if ($selectedPath === '' || !in_array($selectedPath, $validPaths, true)) {
            return null;
        }

        $confidence = (float) ($selected['confidence'] ?? 0.0);
        if ($confidence < 0.35) {
            return null;
        }

        return [
            'use_brand' => true,
            'path' => $selectedPath,
            'reason' => 'brief_visual_match',
            'confidence' => round(max(0.0, min(1.0, $confidence)), 2),
            'model_reason' => Str::limit((string) ($selected['reason'] ?? ''), 180, ''),
        ];
    }

    public function briefMeaningfulTokens(string $normalized): array
    {
        $stop = [
            'con', 'senza', 'della', 'delle', 'degli', 'dello', 'dell', 'dalla', 'dalle', 'dallo',
            'dove', 'come', 'questa', 'questo', 'quello', 'quella', 'immagine', 'foto', 'post',
            'social', 'contenuto', 'crea', 'creami', 'genera', 'genera', 'ultim', 'ultima', 'latest',
            'image', 'logo', 'dietro', 'sopra', 'sotto', 'solo', 'anche', 'molto', 'poco', 'voglio',
        ];
        $stopLookup = array_fill_keys($stop, true);

        $parts = preg_split('/\s+/', $normalized) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (isset($stopLookup[$p])) {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    public function totalItemsInPlan(ContentItem $item): int
    {
        return (int) ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $itemBrain
     * @param  array<int, string>  $planTitles
     * @param  array<int, string>  $planCaptions
     * @return array<string, mixed>
     */
    public function buildSocialPublicationContext(
        ContentItem $item,
        array $itemBrain,
        array $planTitles,
        array $planCaptions
    ): array {
        return [
            'is_social_post' => true,
            'platforms' => $item->platforms(),
            'format' => (string) ($item->format ?? 'post'),
            'feed_position' => $this->positionInPlan($item) + 1,
            'feed_total' => max(1, $this->totalItemsInPlan($item)),
            'series_name' => (string) data_get($itemBrain, 'series_name', ''),
            'series_step' => data_get($itemBrain, 'series_step'),
            'connection_hint' => (string) data_get($itemBrain, 'connection_hint', ''),
            'standalone_rule' => (string) data_get($itemBrain, 'standalone_rule', ''),
            'viral_hook_style' => (string) data_get($itemBrain, 'viral_hook_style', ''),
            'shareability_driver' => (string) data_get($itemBrain, 'shareability_driver', ''),
            'trend_bridge' => (string) data_get($itemBrain, 'trend_bridge', ''),
            'overlay_brief' => (string) data_get($itemBrain, 'overlay_brief', ''),
            'continuity_brief' => (string) data_get($itemBrain, 'continuity_brief', ''),
            'goal' => 'Creare un contenuto pensato per il feed social: chiaro, memorabile, fermascroll e coerente con l insieme delle pubblicazioni.',
            'nearby_titles' => array_values(array_slice($planTitles, 0, 5)),
            'nearby_captions' => array_values(array_slice($planCaptions, 0, 4)),
        ];
    }

    public function positionInPlan(ContentItem $item): int
    {
        $ids = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $position = array_search($item->id, $ids, true);
        if ($position === false) {
            return max(0, count($ids) - 1);
        }
        return (int) $position;
    }

    public function usedBrandImagePathsInPlan(int $tenantId, int $contentPlanId, int $excludeItemId): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('content_plan_id', $contentPlanId)
            ->where('id', '!=', $excludeItemId)
            ->whereNotNull('ai_meta')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['ai_meta']);

        $used = [];
        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $path = data_get($meta, 'image_generation.brand_source_path');
            if (is_string($path) && $path !== '') {
                $used[] = $path;
            }
        }

        return array_values(array_unique($used));
    }

    public function planAlreadyUsedBrandImage(ContentItem $item): bool
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_meta')
            ->get(['ai_meta']);

        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            if ((string) data_get($meta, 'image_generation.source', '') === 'brand_image_edit') {
                return true;
            }
        }

        return false;
    }

    public function computeImageHashFromBytes(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }

        $thumb = imagecreatetruecolor(8, 8);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));

        $vals = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $vals[] = (int) round(($r + $g + $b) / 3);
            }
        }
        $avg = array_sum($vals) / max(1, count($vals));

        $bits = '';
        foreach ($vals as $v) {
            $bits .= ($v >= $avg) ? '1' : '0';
        }

        imagedestroy($thumb);
        imagedestroy($img);

        return $bits;
    }

    public function loadRecentImageHashes(int $tenantId, int $excludeItemId, int $limit = 24): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $excludeItemId)
            ->whereNotNull('ai_image_path')
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['ai_image_path', 'ai_meta']);

        $out = [];
        $disk = Storage::disk('public');
        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $hash = data_get($meta, 'image_generation.image_hash');
            if (is_string($hash) && strlen($hash) === 64) {
                $out[] = $hash;
                continue;
            }

            $path = (string) $row->ai_image_path;
            if ($path === '' || !$disk->exists($path)) {
                continue;
            }
            $bytes = $disk->get($path);
            $computed = $this->computeImageHashFromBytes($bytes);
            if ($computed !== null) {
                $out[] = $computed;
            }
        }

        return array_values(array_unique($out));
    }

    public function maxImageHashSimilarity(?string $hash, array $otherHashes): float
    {
        if (!$hash || empty($otherHashes)) {
            return 0.0;
        }
        $max = 0.0;
        foreach ($otherHashes as $other) {
            if (!is_string($other) || strlen($other) !== strlen($hash)) {
                continue;
            }
            $distance = 0;
            for ($i = 0; $i < strlen($hash); $i++) {
                if ($hash[$i] !== $other[$i]) {
                    $distance++;
                }
            }
            $sim = 1.0 - ($distance / max(1, strlen($hash)));
            if ($sim > $max) {
                $max = $sim;
            }
        }
        return $max;
    }

    public function loadBrandAssetsFromDb(int $tenantId): array
    {
        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->orderByDesc('id')
            ->limit(48)
            ->get(['id', 'kind', 'path', 'original_name', 'mime', 'ai_description', 'ai_context', 'ai_tags'])
            ->map(fn ($asset) => [
                'id'             => (int) $asset->id,
                'kind'           => (string) $asset->kind,
                'path'           => (string) $asset->path,
                'original_name'  => (string) ($asset->original_name ?? ''),
                'mime'           => (string) ($asset->mime ?? ''),
                'ai_description' => (string) ($asset->ai_description ?? ''),
                'ai_context'     => (string) ($asset->ai_context ?? ''),
                'ai_tags'        => is_array($asset->ai_tags) ? $asset->ai_tags : [],
            ])
            ->values()
            ->all();
    }

    public function mergeBrandAssets(array $fromMeta, array $fromDb): array
    {
        $all = array_merge($fromMeta, $fromDb);
        $out = [];
        $seen = [];
        foreach ($all as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $kind = trim((string) ($asset['kind'] ?? ''));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($kind === '' || $path === '') {
                continue;
            }
            $key = $kind . '|' . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'id' => isset($asset['id']) ? (int) $asset['id'] : null,
                'kind' => $kind,
                'path' => $path,
                'original_name' => (string) ($asset['original_name'] ?? ''),
                'mime' => (string) ($asset['mime'] ?? ''),
            ];
        }
        return $out;
    }

    public function uniqueAssets(array $assets): array
    {
        $out = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $path = (string) ($asset['path'] ?? '');
            $type = (string) ($asset['type'] ?? '');
            if ($path === '') {
                continue;
            }
            $key = $type . '|' . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['type' => $type ?: 'asset', 'path' => $path];
        }
        return $out;
    }

    /**
     * Arricchisce il video con una traccia audio quando assente:
     * 1) prova audio da brand video reference
     * 2) fallback TTS da caption/titolo
     *
     * @return array{
     *   applied:bool,
     *   reason:string,
     *   source:string|null,
     *   video_path:string|null,
     *   audio_path:string|null,
     *   error:string|null
     * }
     */
    public function maybeAttachAudioTrackToVideo(ContentItem $item, string $videoPath, SpeechSynthesisService $speechSynthesis): array
    {
        if (!(bool) config('generation.video_auto_audio', true)) {
            return [
                'applied' => false,
                'reason' => 'video_auto_audio_disabled',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $videoPath = trim($videoPath);
        if ($videoPath === '') {
            return [
                'applied' => false,
                'reason' => 'missing_video_path',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => null,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $publicDisk = Storage::disk('public');
        if (!$publicDisk->exists($videoPath)) {
            return [
                'applied' => false,
                'reason' => 'video_file_not_found',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => null,
            ];
        }

        $ffmpeg = $this->resolveFfmpegBinary();
        $ffprobe = $this->resolveFfprobeBinary($ffmpeg);
        if (!$this->canRunBinary($ffmpeg)) {
            return [
                'applied' => false,
                'reason' => 'ffmpeg_unavailable',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => 'FFmpeg non disponibile sul server',
            ];
        }
        $ffprobeAvailable = $this->canRunBinary($ffprobe);

        $currentProvider = strtolower(trim((string) data_get($item->ai_meta, 'video_provider', '')));
        if (!$ffprobeAvailable && $currentProvider !== 'runway') {
            return [
                'applied' => false,
                'reason' => 'ffprobe_unavailable_skip_non_runway',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => 'FFprobe non disponibile sul server',
            ];
        }

        $videoAbsPath = $publicDisk->path($videoPath);
        $narration = $this->resolveNarrationTextForVideo($item);
        $videoAlreadyHasAudio = $ffprobeAvailable && $this->videoHasAudioStream($videoAbsPath, $ffprobe);
        if ($videoAlreadyHasAudio && $narration === '') {
            return [
                'applied' => false,
                'reason' => 'video_already_has_audio',
                'source' => null,
                'provider' => null,
                'voice_id' => null,
                'voice_label' => null,
                'video_path' => $videoPath,
                'audio_path' => null,
                'error' => null,
            ];
        }
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $tempAudioAbs = null;
        $tempMuxedAbs = null;
        $storedAudioPath = null;
        $source = null;
        $provider = null;
        $voiceId = null;
        $voiceLabel = null;
        $error = null;
        try {
            if ($narration !== '') {
                $voiceContext = $this->resolvePersonaVoiceContext($item);
                if (!empty($voiceContext)) {
                    try {
                        $voiceVariable = $this->resolvePersonaVoiceVariable($item);
                        $speechPayload = $speechSynthesis->synthesizeWithVoiceContext(
                            text: $narration,
                            variable: $voiceVariable,
                            voiceContext: $voiceContext,
                            providerMatrix: (array) data_get($item->ai_meta, 'provider_matrix', [])
                        );

                        if (is_array($speechPayload)) {
                            $stored = $this->storeGeneratedAudioPayload($publicDisk, $speechPayload);
                            if (!empty($stored['absolute_path'])) {
                                $tempAudioAbs = (string) $stored['absolute_path'];
                                $storedAudioPath = (string) ($stored['path'] ?? null);
                                $source = (string) ($speechPayload['source'] ?? 'persona_voice_clone');
                                $provider = (string) ($speechPayload['provider'] ?? '');
                                $voiceId = isset($speechPayload['voice_id']) ? (string) $speechPayload['voice_id'] : null;
                                $voiceLabel = isset($speechPayload['label']) ? (string) $speechPayload['label'] : null;
                            }
                        }
                    } catch (Throwable $ttsError) {
                        $error = Str::limit($ttsError->getMessage(), 240, '');
                    }
                }
            }

            if ($tempAudioAbs === null) {
                $brandVideoRefPath = $this->resolveBrandVideoReferencePath($item);
                if ($brandVideoRefPath !== '' && $publicDisk->exists($brandVideoRefPath)) {
                    $brandVideoAbs = $publicDisk->path($brandVideoRefPath);
                    if (!$ffprobeAvailable || $this->videoHasAudioStream($brandVideoAbs, $ffprobe)) {
                        $extractedAbs = $tmpDir . DIRECTORY_SEPARATOR . 'brand-audio-' . Str::uuid()->toString() . '.m4a';
                        if ($this->extractAudioTrackFromVideo($brandVideoAbs, $extractedAbs, $ffmpeg)) {
                            $tempAudioAbs = $extractedAbs;
                            $source = 'brand_video_audio';
                            $provider = null;
                            $voiceId = null;
                            $voiceLabel = 'Audio reale da video brand';
                        }
                    }
                }
            }

            if ($tempAudioAbs === null && $narration !== '') {
                try {
                    $speechPayload = $speechSynthesis->synthesizeWithDefaultVoice($narration);
                    if (is_array($speechPayload)) {
                        $stored = $this->storeGeneratedAudioPayload($publicDisk, $speechPayload);
                        if (!empty($stored['absolute_path'])) {
                            $tempAudioAbs = (string) $stored['absolute_path'];
                            $storedAudioPath = (string) ($stored['path'] ?? null);
                            $source = (string) ($speechPayload['source'] ?? 'openai_tts');
                            $provider = (string) ($speechPayload['provider'] ?? '');
                            $voiceId = isset($speechPayload['voice_id']) ? (string) $speechPayload['voice_id'] : null;
                            $voiceLabel = isset($speechPayload['label']) ? (string) $speechPayload['label'] : null;
                        }
                    }
                } catch (Throwable $ttsError) {
                    $error = Str::limit($ttsError->getMessage(), 240, '');
                }
            }

            if ($tempAudioAbs === null || !is_file($tempAudioAbs)) {
                return [
                    'applied' => false,
                    'reason' => 'no_audio_source_available',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error,
                ];
            }

            $tempMuxedAbs = $tmpDir . DIRECTORY_SEPARATOR . 'video-audio-' . Str::uuid()->toString() . '.mp4';
            if (!$this->muxVideoWithAudioTrack($videoAbsPath, $tempAudioAbs, $tempMuxedAbs, $ffmpeg)) {
                return [
                    'applied' => false,
                    'reason' => 'mux_failed',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error ?: 'FFmpeg non ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨ riuscito ad agganciare l audio al video',
                ];
            }

            $newVideoPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.mp4';
            $bytes = @file_get_contents($tempMuxedAbs);
            if (!is_string($bytes) || $bytes === '') {
                return [
                    'applied' => false,
                    'reason' => 'mux_output_empty',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error,
                ];
            }

            $publicDisk->put($newVideoPath, $bytes);
            if (!$publicDisk->exists($newVideoPath)) {
                return [
                    'applied' => false,
                    'reason' => 'mux_output_store_failed',
                    'source' => $source,
                    'provider' => $provider,
                    'voice_id' => $voiceId,
                    'voice_label' => $voiceLabel,
                    'video_path' => $videoPath,
                    'audio_path' => $storedAudioPath,
                    'error' => $error,
                ];
            }

            return [
                'applied' => true,
                'reason' => 'audio_attached',
                'source' => $source,
                'provider' => $provider,
                'voice_id' => $voiceId,
                'voice_label' => $voiceLabel,
                'video_path' => $newVideoPath,
                'audio_path' => $storedAudioPath,
                'error' => $error,
            ];
        } catch (Throwable $e) {
            return [
                'applied' => false,
                'reason' => 'audio_attach_exception',
                'source' => $source,
                'provider' => $provider,
                'voice_id' => $voiceId,
                'voice_label' => $voiceLabel,
                'video_path' => $videoPath,
                'audio_path' => $storedAudioPath,
                'error' => Str::limit($e->getMessage(), 240, ''),
            ];
        } finally {
            if (is_string($tempMuxedAbs) && $tempMuxedAbs !== '' && is_file($tempMuxedAbs)) {
                @unlink($tempMuxedAbs);
            }

            if (
                is_string($tempAudioAbs)
                && $tempAudioAbs !== ''
                && is_file($tempAudioAbs)
                && ($storedAudioPath === null || !str_contains(str_replace('\\', '/', $tempAudioAbs), '/storage/app/public/'))
            ) {
                @unlink($tempAudioAbs);
            }
        }
    }
    public function resolveNarrationTextForVideo(ContentItem $item): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $storyboardNarration = $this->resolveStoryboardNarrationText($meta);
        if ($storyboardNarration !== '') {
            return $this->sanitizeNarrationText($storyboardNarration);
        }

        $voiceover = trim((string) data_get($meta, 'video_voiceover', ''));
        if ($voiceover !== '') {
            return $this->sanitizeNarrationText($voiceover);
        }

        $caption = trim((string) ($item->ai_caption ?? ''));
        if ($caption === '') {
            $caption = trim((string) ($item->caption ?? ''));
        }

        if ($caption !== '') {
            return $this->sanitizeNarrationText($this->compactCaptionForVoiceover($caption));
        }
        $title = trim((string) ($item->title ?? ''));
        $brief = trim((string) data_get($meta, 'manual_brief', ''));
        $angle = trim((string) data_get($meta, 'item_brain.angle', ''));
        $fallback = trim(implode('. ', array_values(array_filter([$title, $brief !== '' ? Str::limit($brief, 180, '') : '', $angle]))));
        if ($fallback !== '') {
            return $this->sanitizeNarrationText($fallback);
        }
        return '';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function resolveStoryboardNarrationText(array $meta): string
    {
        $fullText = trim((string) data_get($meta, 'storyboard_meta.speech_plan.full_text', ''));
        if ($fullText !== '') {
            return $fullText;
        }

        $segments = array_values(array_filter(array_map(
            fn ($segment) => is_array($segment) ? trim((string) ($segment['text'] ?? '')) : '',
            (array) data_get($meta, 'storyboard_meta.speech_plan.segments', [])
        )));

        if ($segments !== []) {
            return trim(implode(' ', $segments));
        }

        return '';
    }

    public function sanitizeNarrationText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/#\w+/u', '', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/iu', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $maxChars = (int) (config('openai.speech_max_chars') ?: 1000);
        $maxChars = max(200, min(4000, $maxChars));
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = trim(mb_substr($text, 0, $maxChars, 'UTF-8'));
        }

        return $text;
    }

    public function compactCaptionForVoiceover(string $caption): string
    {
        $text = trim($caption);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^[\p{So}\p{Sk}\p{Cf}\s]+/u', '', $text) ?? $text;
        $text = preg_replace('/\b(contesto|azione|risultato|follow-up)\s*:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/#\w+/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: [];
        $clean = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if (preg_match('/\b(contattaci|scrivici|prenota|clicca|salva il post|seguici)\b/iu', $sentence) === 1) {
                continue;
            }

            $clean[] = $sentence;
            if (count($clean) >= 2) {
                break;
            }
        }

        return trim(implode(' ', $clean));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path:?string, absolute_path:?string}
     */
    public function storeGeneratedAudioPayload($publicDisk, array $payload): array
    {
        $bytes = $payload['bytes'] ?? null;
        if (!is_string($bytes) || $bytes === '') {
            return ['path' => null, 'absolute_path' => null];
        }

        $extension = strtolower(trim((string) ($payload['extension'] ?? 'mp3')));
        if ($extension === '' || preg_match('/^[a-z0-9]{2,5}$/', $extension) !== 1) {
            $extension = 'mp3';
        }

        $path = 'ai/audio/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $extension;
        $publicDisk->put($path, $bytes);
        if (!$publicDisk->exists($path)) {
            return ['path' => null, 'absolute_path' => null];
        }

        return [
            'path' => $path,
            'absolute_path' => $publicDisk->path($path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePersonaVoiceContext(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $candidates = [];

        $presenter = data_get($meta, 'asset_identity.slots.presenter', null);
        if (is_array($presenter)) {
            $candidates[] = $presenter;
        }

        foreach ($this->normalizeAssetVariableRows((array) data_get($meta, 'asset_variables.resolved', [])) as $row) {
            if (strtolower(trim((string) ($row['kind'] ?? 'custom'))) !== 'person') {
                continue;
            }

            $candidates[] = $row;
        }

        foreach ($candidates as $row) {
            if (!is_array($row)) {
                continue;
            }

            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
            $assetPath = trim((string) ($row['voice_asset_path'] ?? data_get($profile, 'voice_reference.sample_path', '')));
            $providerVoiceId = trim((string) ($row['voice_provider_voice_id'] ?? data_get($profile, 'voice_reference.provider_voice_id', '')));
            if ($assetPath === '' && $providerVoiceId === '') {
                continue;
            }

            return [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => trim((string) ($row['name'] ?? '')),
                'description' => trim((string) (($row['description'] ?? '') ?: data_get($profile, 'identity_summary', ''))),
                'asset_path' => $assetPath,
                'asset_name' => trim((string) ($row['voice_asset_name'] ?? data_get($profile, 'voice_reference.sample_name', ''))),
                'provider' => trim((string) ($row['voice_provider'] ?? data_get($profile, 'voice_reference.provider', ''))),
                'provider_voice_id' => $providerVoiceId,
                'status' => trim((string) ($row['voice_status'] ?? data_get($profile, 'voice_reference.status', ''))),
                'label' => trim((string) data_get($profile, 'voice_reference.label', 'Voce persona')),
            ];
        }

        return [];
    }

    public function resolvePersonaVoiceVariable(ContentItem $item): ?AssetVariable
    {
        $voiceContext = $this->resolvePersonaVoiceContext($item);
        $variableId = (int) ($voiceContext['id'] ?? 0);
        if ($variableId < 1) {
            return null;
        }

        return AssetVariable::query()
            ->where('tenant_id', $item->tenant_id)
            ->find($variableId);
    }
    public function resolveBrandVideoReferencePath(ContentItem $item): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $candidate = trim((string) data_get($meta, 'video_reference.path', ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $assets = is_array($item->assets) ? $item->assets : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            if ($type === 'brand_video') {
                return $path;
            }
        }

        return '';
    }

    public function extractAudioTrackFromVideo(string $sourceVideoAbs, string $targetAudioAbs, string $ffmpegBinary): bool
    {
        $process = new Process([
            $ffmpegBinary,
            '-y',
            '-i',
            $sourceVideoAbs,
            '-vn',
            '-acodec',
            'aac',
            '-b:a',
            '160k',
            $targetAudioAbs,
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful() && is_file($targetAudioAbs) && filesize($targetAudioAbs) > 0;
    }

    /**
     * Ripulisce gli output video che richiedono una normalizzazione locale per la preview/playback.
     *
     * @return array{
     *   applied:bool,
     *   reason:string,
     *   provider:string,
     *   input_video_path:string,
     *   video_path:string,
     *   error:?string
     * }
     */
    public function postProcessGeneratedVideoForPlayback(ContentItem $item, string $videoPath, string $provider): array
    {
        $videoPath = trim($videoPath);
        $provider = strtolower(trim($provider));

        if ($videoPath === '') {
            return [
                'applied' => false,
                'reason' => 'missing_video_path',
                'provider' => $provider,
                'input_video_path' => '',
                'video_path' => '',
                'error' => null,
            ];
        }

        if ($provider !== 'runway') {
            return [
                'applied' => false,
                'reason' => 'provider_passthrough',
                'provider' => $provider,
                'input_video_path' => $videoPath,
                'video_path' => $videoPath,
                'error' => null,
            ];
        }

        $trimSeconds = (float) config('runway.playback_trim_seconds', 0.35);
        if ($trimSeconds <= 0) {
            return [
                'applied' => false,
                'reason' => 'trim_disabled',
                'provider' => $provider,
                'input_video_path' => $videoPath,
                'video_path' => $videoPath,
                'error' => null,
            ];
        }

        $publicDisk = Storage::disk('public');
        if (!$publicDisk->exists($videoPath)) {
            return [
                'applied' => false,
                'reason' => 'source_video_missing',
                'provider' => $provider,
                'input_video_path' => $videoPath,
                'video_path' => $videoPath,
                'error' => null,
            ];
        }

        $ffmpeg = $this->resolveFfmpegBinary();
        if (!$this->canRunBinary($ffmpeg)) {
            return [
                'applied' => false,
                'reason' => 'ffmpeg_unavailable',
                'provider' => $provider,
                'input_video_path' => $videoPath,
                'video_path' => $videoPath,
                'error' => 'FFmpeg non disponibile sul server',
            ];
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $sourceAbs = $publicDisk->path($videoPath);
        $tempOutputAbs = $tmpDir . DIRECTORY_SEPARATOR . 'runway-playback-' . Str::uuid()->toString() . '.mp4';
        $storedOutputPath = $videoPath;

        try {
            $process = new Process([
                $ffmpeg,
                '-y',
                '-ss',
                number_format($trimSeconds, 2, '.', ''),
                '-i',
                $sourceAbs,
                '-map',
                '0:v:0',
                '-map',
                '0:a?',
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                '22',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-b:a',
                '160k',
                '-movflags',
                '+faststart',
                $tempOutputAbs,
            ]);
            $process->setTimeout(240);
            $process->run();

            if (!$process->isSuccessful() || !is_file($tempOutputAbs) || filesize($tempOutputAbs) <= 0) {
                return [
                    'applied' => false,
                    'reason' => 'trim_process_failed',
                    'provider' => $provider,
                    'input_video_path' => $videoPath,
                    'video_path' => $videoPath,
                    'error' => Str::limit(trim((string) $process->getErrorOutput()) ?: trim((string) $process->getOutput()), 240, ''),
                ];
            }

            $targetPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.mp4';
            $bytes = @file_get_contents($tempOutputAbs);
            if (!is_string($bytes) || $bytes === '') {
                return [
                    'applied' => false,
                    'reason' => 'trim_output_empty',
                    'provider' => $provider,
                    'input_video_path' => $videoPath,
                    'video_path' => $videoPath,
                    'error' => null,
                ];
            }

            $publicDisk->put($targetPath, $bytes);
            if ($publicDisk->exists($targetPath)) {
                $storedOutputPath = $targetPath;
            }

            return [
                'applied' => $storedOutputPath !== $videoPath,
                'reason' => $storedOutputPath !== $videoPath ? 'runway_trimmed_for_playback' : 'trim_store_failed',
                'provider' => $provider,
                'input_video_path' => $videoPath,
                'video_path' => $storedOutputPath,
                'error' => $storedOutputPath !== $videoPath ? null : 'Impossibile salvare il video normalizzato',
            ];
        } catch (Throwable $e) {
            return [
                'applied' => false,
                'reason' => 'trim_exception',
                'provider' => $provider,
                'input_video_path' => $videoPath,
                'video_path' => $videoPath,
                'error' => Str::limit($e->getMessage(), 240, ''),
            ];
        } finally {
            if (is_file($tempOutputAbs)) {
                @unlink($tempOutputAbs);
            }
        }
    }

    public function muxVideoWithAudioTrack(string $sourceVideoAbs, string $audioAbs, string $targetVideoAbs, string $ffmpegBinary): bool
    {
        $process = new Process([
            $ffmpegBinary,
            '-y',
            '-i',
            $sourceVideoAbs,
            '-i',
            $audioAbs,
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '22',
            '-c:a',
            'aac',
            '-b:a',
            '160k',
            '-af',
            'apad',
            '-shortest',
            $targetVideoAbs,
        ]);
        $process->setTimeout(240);
        $process->run();

        return $process->isSuccessful() && is_file($targetVideoAbs) && filesize($targetVideoAbs) > 0;
    }

    /**
     * @param  array<int, string>  $videoPaths
     */
    public function concatenateVideoSegments(array $videoPaths): string
    {
        $publicDisk = Storage::disk('public');
        $paths = array_values(array_filter(
            array_map(fn ($path) => trim((string) $path), $videoPaths),
            fn ($path) => $path !== ''
        ));

        if (count($paths) < 2) {
            throw new \RuntimeException('Servono almeno due segmenti per concatenare un reel esteso.');
        }

        $ffmpeg = $this->resolveFfmpegBinary();
        if (!$this->canRunBinary($ffmpeg)) {
            throw new \RuntimeException('FFmpeg non disponibile per concatenare i segmenti video.');
        }

        $sourceAbsPaths = [];
        foreach ($paths as $path) {
            if (!$publicDisk->exists($path)) {
                throw new \RuntimeException("Segmento video non trovato per la concatenazione: {$path}");
            }

            $sourceAbsPaths[] = $publicDisk->path($path);
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $tempOutputAbs = $tmpDir . DIRECTORY_SEPARATOR . 'extended-reel-' . Str::uuid()->toString() . '.mp4';

        try {
            $command = [$ffmpeg, '-y'];
            foreach ($sourceAbsPaths as $sourceAbs) {
                $command[] = '-i';
                $command[] = $sourceAbs;
            }

            $filterInputs = '';
            foreach (array_keys($sourceAbsPaths) as $index) {
                $filterInputs .= '[' . $index . ':v:0]';
            }

            $command[] = '-filter_complex';
            $command[] = $filterInputs . 'concat=n=' . count($sourceAbsPaths) . ':v=1:a=0[v]';
            $command[] = '-map';
            $command[] = '[v]';
            $command[] = '-c:v';
            $command[] = 'libx264';
            $command[] = '-preset';
            $command[] = 'veryfast';
            $command[] = '-crf';
            $command[] = '22';
            $command[] = '-pix_fmt';
            $command[] = 'yuv420p';
            $command[] = '-movflags';
            $command[] = '+faststart';
            $command[] = $tempOutputAbs;

            $process = new Process($command);
            $process->setTimeout(900);
            $process->run();

            if (!$process->isSuccessful() || !is_file($tempOutputAbs) || filesize($tempOutputAbs) <= 0) {
                $error = trim((string) $process->getErrorOutput()) ?: trim((string) $process->getOutput());
                throw new \RuntimeException('Concatenazione FFmpeg fallita: ' . Str::limit($error, 320, ''));
            }

            $targetPath = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.mp4';
            $bytes = @file_get_contents($tempOutputAbs);
            if (!is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('Il file video concatenato risulta vuoto.');
            }

            $publicDisk->put($targetPath, $bytes);
            if (!$publicDisk->exists($targetPath)) {
                throw new \RuntimeException('Impossibile salvare il reel concatenato sul disco pubblico.');
            }

            return $targetPath;
        } finally {
            if (is_file($tempOutputAbs)) {
                @unlink($tempOutputAbs);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $audioAttach
     */
    public function logVideoAudioOutcome(ContentItem $item, array $audioAttach): void
    {
        $context = [
            'content_item_id' => $item->id,
            'reason' => (string) ($audioAttach['reason'] ?? ''),
            'applied' => (bool) ($audioAttach['applied'] ?? false),
            'source' => $audioAttach['source'] ?? null,
            'provider' => $audioAttach['provider'] ?? null,
            'voice_id' => $audioAttach['voice_id'] ?? null,
            'voice_label' => $audioAttach['voice_label'] ?? null,
            'video_path' => $audioAttach['video_path'] ?? null,
            'audio_path' => $audioAttach['audio_path'] ?? null,
            'error' => $audioAttach['error'] ?? null,
            'postprocess_reason' => data_get($audioAttach, 'postprocess.reason'),
            'postprocess_applied' => (bool) data_get($audioAttach, 'postprocess.applied', false),
        ];

        if ((bool) ($audioAttach['applied'] ?? false)) {
            Log::info('GenerateAiForContentItem video audio attached', $context);
            return;
        }

        Log::warning('GenerateAiForContentItem video audio skipped', $context);
    }

    public function videoHasAudioStream(string $videoAbsPath, string $ffprobeBinary): bool
    {
        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_entries',
            'stream=codec_type',
            '-of',
            'csv=p=0',
            $videoAbsPath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        $output = strtolower(trim((string) $process->getOutput()));
        return $output !== '' && str_contains($output, 'audio');
    }

    public function resolveFfmpegBinary(): string
    {
        $configured = trim((string) config('generation.ffmpeg_binary', ''));
        $fallback = $configured !== '' ? $configured : $this->defaultBinaryCommand('ffmpeg');

        return $this->firstAvailableBinary(
            array_merge(
                $configured !== '' ? [$configured] : [],
                $this->candidateBinaries('ffmpeg')
            ),
            $fallback
        );
    }

    public function resolveFfprobeBinary(string $ffmpegBinary): string
    {
        $configured = trim((string) config('generation.ffprobe_binary', ''));
        if ($configured !== '' && $this->canRunBinary($configured)) {
            return $configured;
        }

        $derived = $this->deriveSiblingBinary($ffmpegBinary, 'ffmpeg', 'ffprobe');
        if ($derived !== null && $this->canRunBinary($derived)) {
            return $derived;
        }

        $fallback = $configured !== '' ? $configured : $this->defaultBinaryCommand('ffprobe');

        return $this->firstAvailableBinary(
            array_merge(
                $configured !== '' ? [$configured] : [],
                $this->candidateBinaries('ffprobe')
            ),
            $fallback
        );
    }

    public function canRunBinary(string $binary): bool
    {
        $binary = trim($binary);
        if ($binary === '') {
            return false;
        }

        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(6);
            $process->run();
            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    public function candidateBinaries(string $binary): array
    {
        $default = $this->defaultBinaryCommand($binary);

        if (PHP_OS_FAMILY === 'Windows') {
            $windowsName = $binary . '.exe';

            return [
                $default,
                'C:\\Program Files\\ffmpeg\\bin\\' . $windowsName,
                'C:\\ffmpeg\\bin\\' . $windowsName,
                'C:\\laragon\\bin\\ffmpeg\\' . $windowsName,
                'C:\\laragon\\bin\\ffmpeg\\bin\\' . $windowsName,
                'C:\\Program Files\\Wondershare\\Recoverit - Data Recovery (CPC)\\' . $windowsName,
            ];
        }

        return [
            $default,
            '/usr/bin/' . $binary,
            '/usr/local/bin/' . $binary,
            '/opt/homebrew/bin/' . $binary,
        ];
    }

    public function defaultBinaryCommand(string $binary): string
    {
        return PHP_OS_FAMILY === 'Windows' ? $binary . '.exe' : $binary;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    public function firstAvailableBinary(array $candidates, string $fallback): string
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || in_array($candidate, $unique, true)) {
                continue;
            }

            $unique[] = $candidate;
        }

        foreach ($unique as $candidate) {
            if ($this->canRunBinary($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    public function deriveSiblingBinary(string $sourceBinary, string $sourceName, string $targetName): ?string
    {
        $normalized = str_replace('\\', '/', trim($sourceBinary));
        if ($normalized === '') {
            return null;
        }

        $sourceExe = '/' . $sourceName . '.exe';
        if (str_ends_with(strtolower($normalized), $sourceExe)) {
            return substr($sourceBinary, 0, -strlen($sourceName . '.exe')) . $targetName . '.exe';
        }

        $sourcePlain = '/' . $sourceName;
        if (str_ends_with(strtolower($normalized), $sourcePlain)) {
            return substr($sourceBinary, 0, -strlen($sourceName)) . $targetName;
        }

        return null;
    }

    public function attachBrandVideoReference(ContentItem $item): void
    {
        $format = strtolower((string) ($item->format ?? ''));
        if (!in_array($format, ['reel', 'story', 'video'], true)) {
            return;
        }

        $videoPath = '';
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $resolvedVariables = $this->normalizeAssetVariableRows((array) data_get($meta, 'asset_variables.resolved', []));

        foreach ($resolvedVariables as $row) {
            if (strtolower(trim((string) ($row['kind'] ?? 'custom'))) !== 'person') {
                continue;
            }

            $candidate = trim((string) data_get($row, 'profile.reference_video_path', ''));
            if ($candidate === '' && !empty($row['assets']) && is_array($row['assets'])) {
                foreach ((array) $row['assets'] as $asset) {
                    if (!is_array($asset)) {
                        continue;
                    }

                    $assetKind = strtolower(trim((string) ($asset['kind'] ?? '')));
                    $assetPath = trim((string) ($asset['path'] ?? ''));
                    if ($assetKind === 'video' && $assetPath !== '') {
                        $candidate = $assetPath;
                        break;
                    }
                }
            }

            if ($candidate !== '') {
                $videoPath = $candidate;
                break;
            }
        }

        if ($videoPath === '') {
            $videoPath = BrandAsset::query()
                ->where('tenant_id', $item->tenant_id)
                ->whereNull('content_plan_id')
                ->where('kind', 'video')
                ->orderBy('id')
                ->value('path');
        }

        if (!is_string($videoPath) || trim($videoPath) === '') {
            return;
        }

        $assets = is_array($item->assets) ? $item->assets : [];
        $assets[] = ['type' => 'brand_video', 'path' => $videoPath];
        $item->assets = $this->uniqueAssets($assets);

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['video_reference'] = [
            'path' => $videoPath,
            'kind' => 'brand_video',
        ];
        $item->ai_meta = $meta;
    }

    public function hasGeneratedVisualOutput(ContentItem $item): bool
    {
        $imagePath = trim((string) ($item->ai_image_path ?? ''));
        if ($imagePath !== '') {
            return true;
        }

        $assets = is_array($item->assets) ? $item->assets : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            if (str_contains($type, 'ai_generated') || $type === 'demo_image') {
                return true;
            }
        }

        return false;
    }
}

























