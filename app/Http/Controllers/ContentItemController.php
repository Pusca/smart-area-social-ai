<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Services\AI\AiProviderMatrixService;
use App\Services\AI\TenantContentIntelligenceService;
use App\Services\AssetIdentityService;
use App\Services\AssetVariableService;
use App\Services\ContentMediaPreviewService;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\MemoryBuilderService;
use App\Services\Social\SocialPublishingService;
use App\Services\TenantQuotaService;
use App\Support\GenerationExecution;
use App\Support\ImageProviderResolver;
use App\Support\UiStatus;
use App\Support\VideoProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ContentItemController extends Controller
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly TenantContentIntelligenceService $tenantContentIntelligence,
        private readonly AiProviderMatrixService $aiProviderMatrixService,
        private readonly AssetIdentityService $assetIdentityService,
        private readonly EditorialStrategyService $editorialStrategyService,
        private readonly ContentMediaPreviewService $mediaPreviewService,
        private readonly AssetVariableService $assetVariableService,
        private readonly SocialPublishingService $socialPublishingService,
        private readonly TenantQuotaService $tenantQuotaService
    ) {
    }

    /**
     * LISTA "POSTS" (la tua pagina attuale) => resources/views/posts/index.blade.php
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $baseQuery = ContentItem::query()->where('tenant_id', $user->tenant_id);

        $items = (clone $baseQuery)
            ->with(['latestFeedbackEntry'])
            ->withCount('feedbackEntries')
            ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $this->mediaPreviewService->attachPreviewData($items->getCollection());

        $statusCounts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'scheduled' => (clone $baseQuery)->whereNotNull('scheduled_at')->count(),
            'published' => (clone $baseQuery)
                ->where(function ($q) {
                    $q->where('status', 'published')->orWhereNotNull('published_at');
                })
                ->count(),
            'ai_done' => (clone $baseQuery)->where('ai_status', 'done')->count(),
            'ai_queued' => (clone $baseQuery)->whereIn('ai_status', ['queued', 'pending'])->count(),
            'ai_error' => (clone $baseQuery)->where('ai_status', 'error')->count(),
            'status_counts' => [
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'review' => (int) ($statusCounts['review'] ?? 0),
                'approved' => (int) ($statusCounts['approved'] ?? 0),
                'scheduled' => (int) ($statusCounts['scheduled'] ?? 0),
                'published' => (int) ($statusCounts['published'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
            ],
        ];

        $tz = config('app.timezone', 'Europe/Rome');
        $todayStart = now($tz)->startOfDay();
        $todayEnd = now($tz)->endOfDay();

        $todayItems = (clone $baseQuery)
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->orderBy('scheduled_at')
            ->get();

        $todayPending = $todayItems->where('status', '!=', 'published')->count();

        $nextItem = (clone $baseQuery)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now($tz))
            ->orderBy('scheduled_at')
            ->first();

        return view('posts.index', compact('items', 'stats', 'todayItems', 'todayPending', 'nextItem'));
    }

    /**
     * LISTA "CONTENT ITEMS" (nuova galleria con immagini) => resources/views/content-items/index.blade.php
     */
    public function gallery(Request $request)
    {
        $user = $request->user();

        $q = ContentItem::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        // filtri opzionali (se li aggiungi in futuro)
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('platform')) {
            $q->where('platform', $request->string('platform')->toString());
        }

        $items = $q->paginate(24)->withQueryString();

        return view('content-items.index', compact('items'));
    }

    /**
     * DETTAGLIO "CONTENT ITEM" (immagine grande) => resources/views/content-items/show.blade.php
     */
    public function show(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        return view('content-items.show', [
            'item' => $contentItem,
        ]);
    }

    public function create(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $profile = TenantProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();

        $referenceImages = $this->loadBrandReferenceImages($tenantId);
        $assetVariables = $this->assetVariableService->catalogForTenant($tenantId);
        $createPreset = $this->normalizeCreatePreset((string) $request->query('preset', ''));

        return view('posts.create', compact('profile', 'referenceImages', 'assetVariables', 'createPreset'));
    }

    public function createReel(Request $request)
    {
        $request->query->set('preset', 'reel');

        return $this->create($request);
    }

    public function generating(Request $request, ContentItem $contentItem): View
    {
        $this->authorizeTenant($request, $contentItem);

        return view('posts.generating', [
            'contentItem' => $contentItem,
            'estimateSeconds' => $this->estimateGenerationSeconds($contentItem),
            'stages' => $this->generationStages($contentItem),
        ]);
    }

    public function generationStatus(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorizeTenant($request, $contentItem);
        $contentItem->refresh();

        $status = (string) ($contentItem->ai_status ?? '');

        return response()->json([
            'item_id' => (int) $contentItem->id,
            'ai_status' => $status,
            'status' => UiStatus::ai($status),
            'active' => in_array($status, ['queued', 'pending'], true),
            'completed' => $status === 'done',
            'error' => $status === 'error',
            'redirect_url' => route('posts.edit', $contentItem),
            'updated_at' => optional($contentItem->updated_at)->toDateTimeString(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $data = $request->validate([
            'platforms' => 'nullable|array|min:1',
            'platforms.*' => 'string|max:50',
            'platform' => 'nullable|string|max:255',
            'format' => 'required|string|max:50',
            'video_provider' => ['nullable', VideoProviderResolver::inRule()],
            'image_provider' => ['nullable', ImageProviderResolver::inRule()],
            'scheduled_at' => 'required|date',
            'generation_brief' => 'nullable|string|max:3000',
            'goal_hint' => 'nullable|string|max:180',
            'title' => 'nullable|string|max:120',
            'caption' => 'nullable|string|max:3000',
            'status' => 'nullable|string|max:30',
            'reference_asset_ids' => 'nullable|array',
            'reference_asset_ids.*' => 'integer',
            'asset_variable_ids' => 'nullable|array',
            'asset_variable_ids.*' => 'integer',
            'presenter_variable_id' => 'nullable|integer',
            'product_variable_id' => 'nullable|integer',
            'place_variable_id' => 'nullable|integer',
            'seasonal_overlay' => 'nullable|string|max:160',
            'consistency_mode' => 'nullable|string|in:strict,balanced,creative',
        ]);

        $platforms = $this->extractPlatforms($request, $data);
        $platformValue = implode(',', $platforms);
        $videoProvider = VideoProviderResolver::normalize((string) ($data['video_provider'] ?? ''));
        $imageProvider = ImageProviderResolver::resolve((string) ($data['image_provider'] ?? ''), ImageProviderResolver::default());

        $brief = trim((string) (
            $data['generation_brief']
            ?? $data['caption']
            ?? $data['title']
            ?? ''
        ));

        if ($brief === '') {
            return back()
                ->withErrors(['generation_brief' => 'Inserisci una descrizione di cosa vuoi che l AI generi.'])
                ->withInput();
        }

        try {
            $this->tenantQuotaService->assertCanCreateContentItems($tenantId, 1);
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['generation_brief' => $e->getMessage()])
                ->withInput();
        }

        $tz = config('app.timezone', 'Europe/Rome');
        $scheduledAt = Carbon::parse((string) $data['scheduled_at'], $tz);

        $status = (string) ($data['status'] ?? '');
        if ($status === '') {
            $status = 'draft';
        }

        $profile = TenantProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();

        $profileData = $this->buildProfileData($profile);
        $assets = $this->loadBrandAssets($tenantId);
        $strictAssetMode = (bool) config('generation.strict_asset_mode', true);
        $brandImageCount = collect($assets)
            ->filter(fn ($asset) => is_array($asset) && (($asset['kind'] ?? null) === 'image') && !empty($asset['path']))
            ->count();

        if ($strictAssetMode && $brandImageCount < 1) {
            return back()
                ->withErrors(['generation_brief' => 'Strict mode attivo: carica almeno 1 immagine in Brand Assets prima di generare contenuti.'])
                ->withInput();
        }

        $requestedReferenceIds = array_values(array_unique(array_map(
            fn ($v) => (int) $v,
            array_filter((array) ($data['reference_asset_ids'] ?? []), fn ($v) => (int) $v > 0)
        )));
        $slotVariableIds = array_values(array_unique(array_filter([
            (int) ($data['presenter_variable_id'] ?? 0),
            (int) ($data['product_variable_id'] ?? 0),
            (int) ($data['place_variable_id'] ?? 0),
        ], fn ($v) => (int) $v > 0)));
        $requestedVariableIds = array_values(array_unique(array_merge(array_map(
            fn ($v) => (int) $v,
            array_filter((array) ($data['asset_variable_ids'] ?? []), fn ($v) => (int) $v > 0)
        ), $slotVariableIds)));

        $assetVariableRefs = $this->assetVariableService->resolveForBrief(
            tenantId: $tenantId,
            brief: $brief,
            requestedIds: $requestedVariableIds
        );
        $assetIdentity = $this->buildAssetIdentityContext($data, $assetVariableRefs);

        if ($strictAssetMode && !empty($requestedVariableIds) && empty((array) ($assetVariableRefs['resolved_ids'] ?? []))) {
            return back()
                ->withErrors(['generation_brief' => 'Le variabili selezionate non sono valide per questo tenant.'])
                ->withInput();
        }

        $explicitImageReferences = $this->resolveExplicitImageReferences(
            $brief,
            $assets,
            $requestedReferenceIds
        );
        $explicitImageReferences = $this->mergeVariablePathsIntoImageReferences(
            $explicitImageReferences,
            (array) ($assetVariableRefs['resolved_asset_paths'] ?? [])
        );
        if (
            $strictAssetMode
            && (!empty($requestedReferenceIds) || !empty((array) data_get($explicitImageReferences, 'numbers_detected_in_brief', [])))
            && empty((array) data_get($explicitImageReferences, 'selected_paths', []))
        ) {
            return back()
                ->withErrors(['generation_brief' => 'Strict mode attivo: i riferimenti immagine indicati non sono validi. Seleziona asset esistenti o usa numeri corretti.'])
                ->withInput();
        }

        $memory = $this->memoryBuilder->buildForTenant($tenantId, 40);

        $strategyModel = $this->editorialStrategyService->refreshForTenant($tenantId, $profile);
        $strategy = $this->editorialStrategyService->toRuntimeContext(
            $strategyModel,
            $this->buildBrandReferences($profileData, $assets, (array) ($assetVariableRefs['catalog'] ?? []))
        );

        $plan = $this->resolvePlanForSingleItem($tenantId, (int) $user->id, $scheduledAt, $strategy);

        $goalHint = trim((string) ($data['goal_hint'] ?? ''));
        $format = (string) $data['format'];
        $tenantIntelligence = $this->tenantContentIntelligence->buildForGeneration($tenantId, $brief, $format, $platforms);
        $providerMatrix = $this->aiProviderMatrixService->resolve([
            'text_provider' => data_get($data, 'text_provider', ''),
            'grader_provider' => data_get($data, 'grader_provider', ''),
            'image_provider' => $imageProvider,
            'video_provider' => $videoProvider,
        ]);
        $internalTitle = Str::limit($brief, 110, '');
        $imagePreference = $explicitImageReferences['primary_preference'] ?? $this->selectPreferredBrandImage($brief, $assets);
        $uniquenessSeed = implode('|', [
            $tenantId,
            $platformValue,
            $format,
            $scheduledAt->format('Y-m-d H:i'),
            $brief,
            microtime(true),
        ]);

        $item = new ContentItem();
        $item->tenant_id = $tenantId;
        $item->content_plan_id = (int) $plan->id;
        $item->created_by = (int) $user->id;

        $item->platform = $platformValue;
        $item->format = $format;
        $item->status = $status;
        $item->title = $internalTitle;
        $item->caption = $brief;
        $item->scheduled_at = $scheduledAt;

        $item->rubric = 'On Demand';
        $item->pillar = 'Richiesta Manuale';
        $item->content_angle = Str::limit($brief, 180, '');
        $item->hashtags = [];
        $item->assets = [];
        $item->source_refs = array_merge(
            $this->buildSourceRefsFromAssetIdentity($assetIdentity),
            $this->buildSourceRefsFromAssetVariables($assetVariableRefs),
            $this->buildSourceRefsFromExplicitImageReferences($explicitImageReferences)
        );
        $item->ai_status = 'queued';
        $item->ai_error = null;
        $item->ai_meta = [
            'source' => 'manual_single_content',
            'video_provider' => $videoProvider,
            'image_provider' => $imageProvider,
            'tenant_profile' => $profileData,
            'brand_assets' => $assets,
            'image_references' => $explicitImageReferences,
            'asset_variables' => $assetVariableRefs,
            'asset_identity' => $assetIdentity,
            'plan' => [
                'goal' => $goalHint !== '' ? $goalHint : data_get($plan->settings, 'goal'),
                'tone' => data_get($plan->settings, 'tone', $profile?->default_tone),
                'posts_total' => 1,
                'platforms' => $platforms,
                'formats' => [$format],
                'date_range' => [$scheduledAt->toDateString(), $scheduledAt->toDateString()],
            ],
            'memory_summary' => $memory,
            'knowledge_pack' => (array) ($tenantIntelligence['knowledge_pack'] ?? []),
            'examples' => (array) ($tenantIntelligence['examples'] ?? []),
            'negative_examples' => (array) ($tenantIntelligence['negative_examples'] ?? []),
            'feedback_signals' => (array) ($tenantIntelligence['feedback_signals'] ?? []),
            'provider_matrix' => $providerMatrix,
            'strategy' => $strategy,
            'item_brain' => [
                'rubric' => 'On Demand',
                'pillar' => 'Richiesta Manuale',
                'angle' => Str::limit($brief, 180, ''),
                'objective' => $goalHint !== '' ? $goalHint : 'Awareness',
                'key_points' => [$brief],
                'cta' => (string) ($profile?->cta ?: 'Scrivici per maggiori informazioni.'),
                'image_direction' => $this->buildImageDirectionWithVariables($brief, $assetVariableRefs, $assetIdentity),
                'series_name' => 'contenuto-singolo',
                'series_step' => 1,
                'standalone_rule' => 'Il contenuto deve essere completo anche se letto singolarmente.',
                'connection_hint' => 'Contenuto singolo richiesto manualmente.',
                'uniqueness_key' => sha1($uniquenessSeed),
            ],
            'manual_brief' => $brief,
            'image_preference' => $imagePreference,
            'created_at' => now()->toDateTimeString(),
        ];

        $item->save();

        $publicationSync = null;
        if (in_array($item->status, ['approved', 'scheduled'], true)) {
            $publicationSync = $this->socialPublishingService->syncForContentItem($item);
            $item->refresh();
        }

        try {
            GenerationExecution::dispatchContentItem((int) $item->id);
        } catch (\Throwable $e) {
            $item->ai_status = 'error';
            $item->ai_error = 'QUEUE: ' . $e->getMessage();
            $item->save();

            return redirect()
                ->route('posts.edit', $item)
                ->with('status', trim('Contenuto creato, ma la generazione AI non e partita: ' . $e->getMessage() . ' ' . $this->publicationSyncMessage($publicationSync)));
        }

        if (GenerationExecution::shouldShowProgressPage()) {
            return redirect()->route('posts.generating', $item);
        }

        return redirect()
            ->route('posts.edit', $item)
            ->with('status', GenerationExecution::shouldRunSync()
                ? trim('Contenuto creato e generato con AI. ' . $this->publicationSyncMessage($publicationSync))
                : trim('Contenuto creato e messo in coda AI. ' . $this->publicationSyncMessage($publicationSync)));
    }

    public function edit(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        $allowsCustomImageProvider = $this->allowsCustomImageProvider($contentItem);
        $contentItem->load([
            'feedbackEntries' => fn ($query) => $query
                ->with('user:id,name')
                ->latest('id')
                ->limit(8),
        ]);

        return view('posts.edit', compact('contentItem', 'allowsCustomImageProvider'));
    }

    public function update(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'format' => 'required|string|max:50',
            'video_provider' => ['nullable', VideoProviderResolver::inRule()],
            'image_provider' => ['nullable', ImageProviderResolver::inRule()],
            'scheduled_at' => 'nullable|date',
            'title' => 'nullable|string|max:120',
            'ai_caption' => 'nullable|string',
            'ai_image_prompt' => 'nullable|string',
            'status' => 'nullable|string|max:30',
        ]);

        $contentItem->platform = $data['platform'];
        $contentItem->format = $data['format'];
        $contentItem->status = $data['status'] ?? $contentItem->status;
        $contentItem->title = $data['title'] ?? null;
        $contentItem->ai_caption = $data['ai_caption'] ?? null;
        $contentItem->ai_image_prompt = $data['ai_image_prompt'] ?? null;
        $contentItem->scheduled_at = !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
        $meta = is_array($contentItem->ai_meta) ? $contentItem->ai_meta : [];
        $existingVideoProvider = (string) data_get($meta, 'video_provider', '');
        $videoProviderCandidate = array_key_exists('video_provider', $data)
            ? (string) ($data['video_provider'] ?? '')
            : $existingVideoProvider;
        $meta['video_provider'] = VideoProviderResolver::resolve($videoProviderCandidate, $existingVideoProvider);
        $existingImageProvider = (string) data_get($meta, 'image_provider', '');
        $meta['image_provider'] = $this->allowsCustomImageProvider($contentItem)
            ? ImageProviderResolver::resolve((string) ($data['image_provider'] ?? ''), $existingImageProvider)
            : ImageProviderResolver::default();
        $meta['provider_matrix'] = $this->aiProviderMatrixService->resolve($meta);
        $contentItem->ai_meta = $meta;

        $contentItem->save();

        $publicationSync = null;
        if (in_array($contentItem->status, ['approved', 'scheduled'], true)) {
            $publicationSync = $this->socialPublishingService->syncForContentItem($contentItem);
            $contentItem->refresh();
        } else {
            $this->socialPublishingService->cancelUnpublishedForItem(
                $contentItem,
                'Contenuto riportato fuori dalla coda di pubblicazione automatica.'
            );
        }

        return redirect()->route('posts.index')->with('status', trim('Contenuto aggiornato. ' . $this->publicationSyncMessage($publicationSync)));
    }

    public function destroy(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        $contentItem->delete();

        return redirect()->route('posts.index')->with('status', 'Contenuto eliminato.');
    }

    private function authorizeTenant(Request $request, ContentItem $item): void
    {
        if ((int) $item->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }

    private function extractPlatforms(Request $request, array $data): array
    {
        $platforms = $data['platforms'] ?? null;
        if (is_array($platforms) && !empty($platforms)) {
            return array_values(array_unique(array_map(
                fn ($v) => Str::lower(trim((string) $v)),
                array_filter($platforms, fn ($v) => trim((string) $v) !== '')
            )));
        }

        $raw = trim((string) ($data['platform'] ?? $request->input('platform', '')));
        if ($raw !== '') {
            $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
            $parts = array_values(array_unique(array_map(
                fn ($v) => Str::lower(trim((string) $v)),
                array_filter($parts, fn ($v) => trim((string) $v) !== '')
            )));
            if (!empty($parts)) {
                return $parts;
            }
        }

        return ['instagram'];
    }

    private function buildProfileData(?TenantProfile $profile): array
    {
        return [
            'business_name' => $profile?->business_name,
            'industry' => $profile?->industry,
            'website' => $profile?->website,
            'services' => $profile?->services,
            'target' => $profile?->target,
            'cta' => $profile?->cta,
            'notes' => $profile?->notes,
            'vision' => $profile?->vision,
            'values' => $profile?->values,
            'business_hours' => $profile?->business_hours,
            'seasonal_offers' => $profile?->seasonal_offers,
            'brand_palette' => $profile?->brand_palette,
        ];
    }

    private function loadBrandAssets(int $tenantId): array
    {
        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->limit(48)
            ->get()
            ->map(fn ($asset) => [
                'id' => (int) $asset->id,
                'kind' => (string) $asset->kind,
                'path' => (string) $asset->path,
                'original_name' => (string) ($asset->original_name ?? ''),
                'mime' => (string) ($asset->mime ?? ''),
            ])
            ->values()
            ->all();
    }

    private function loadBrandReferenceImages(int $tenantId): array
    {
        $rows = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'image')
            ->latest('id')
            ->limit(48)
            ->get(['id', 'path', 'original_name', 'mime']);

        $out = [];
        $refNumber = 1;
        foreach ($rows as $row) {
            $path = trim((string) ($row->path ?? ''));
            if ($path === '') {
                continue;
            }

            $out[] = [
                'id' => (int) $row->id,
                'path' => $path,
                'original_name' => (string) ($row->original_name ?? ''),
                'mime' => (string) ($row->mime ?? ''),
                'ref_number' => $refNumber,
            ];
            $refNumber++;
        }

        return $out;
    }

    private function resolveExplicitImageReferences(string $brief, array $assets, array $requestedAssetIds): array
    {
        $images = [];
        $imagesById = [];
        $imagesByNumber = [];
        $number = 1;

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['kind'] ?? null) !== 'image') {
                continue;
            }

            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $assetId = isset($asset['id']) ? (int) $asset['id'] : 0;
            $row = [
                'id' => $assetId > 0 ? $assetId : null,
                'path' => $path,
                'original_name' => (string) ($asset['original_name'] ?? ''),
                'mime' => (string) ($asset['mime'] ?? ''),
                'ref_number' => $number,
            ];

            $images[] = $row;
            $imagesByNumber[$number] = $row;
            if ($assetId > 0) {
                $imagesById[$assetId] = $row;
            }
            $number++;
        }

        $selected = [];
        $selectionSources = [];
        $numbersFromBrief = $this->extractReferenceNumbersFromBrief($brief, count($images));
        if (!empty($numbersFromBrief)) {
            foreach ($numbersFromBrief as $n) {
                if (!isset($imagesByNumber[$n])) {
                    continue;
                }
                $row = $imagesByNumber[$n];
                $selected[(string) $row['path']] = $row;
            }
            $selectionSources[] = 'brief_numbers';
        } else {
            $ids = array_values(array_unique(array_map(
                fn ($v) => (int) $v,
                array_filter($requestedAssetIds, fn ($v) => (int) $v > 0)
            )));
            foreach ($ids as $id) {
                if (!isset($imagesById[$id])) {
                    continue;
                }
                $row = $imagesById[$id];
                $selected[(string) $row['path']] = $row;
            }
            if (!empty($ids)) {
                $selectionSources[] = 'checkbox';
            }
        }

        $selectedAssets = array_values($selected);
        $selectedIds = [];
        $selectedPaths = [];
        $selectedNumbers = [];
        foreach ($selectedAssets as $row) {
            $assetId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($assetId > 0) {
                $selectedIds[] = $assetId;
            }
            $selectedPaths[] = (string) ($row['path'] ?? '');
            $selectedNumbers[] = (int) ($row['ref_number'] ?? 0);
        }

        $primaryPreference = null;
        if (!empty($selectedPaths)) {
            $primaryPreference = [
                'path' => (string) $selectedPaths[0],
                'reason' => 'explicit_reference_selection',
                'confidence' => 1.0,
            ];
        }

        return [
            'selection_mode' => empty($selectionSources) ? 'none' : implode('+', array_values(array_unique($selectionSources))),
            'selected_ids' => array_values(array_unique(array_filter($selectedIds, fn ($v) => $v > 0))),
            'selected_paths' => array_values(array_unique(array_filter($selectedPaths, fn ($v) => trim((string) $v) !== ''))),
            'selected_numbers' => array_values(array_unique(array_filter($selectedNumbers, fn ($v) => (int) $v > 0))),
            'selected_assets' => $selectedAssets,
            'numbers_detected_in_brief' => $numbersFromBrief,
            'available_total' => count($images),
            'numbering' => array_values($images),
            'primary_preference' => $primaryPreference,
        ];
    }

    private function buildSourceRefsFromExplicitImageReferences(array $refs): array
    {
        $selected = (array) ($refs['selected_assets'] ?? []);
        $out = [];

        foreach ($selected as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = trim((string) ($row['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $out[] = [
                'type' => 'brand_image_reference',
                'asset_id' => isset($row['id']) ? (int) $row['id'] : null,
                'path' => $path,
                'ref_number' => isset($row['ref_number']) ? (int) $row['ref_number'] : null,
                'name' => (string) ($row['original_name'] ?? ''),
            ];

            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private function mergeVariablePathsIntoImageReferences(array $refs, array $variablePaths): array
    {
        $paths = collect((array) ($refs['selected_paths'] ?? []))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn (string $v) => $v !== '')
            ->values()
            ->all();

        $variablePaths = collect($variablePaths)
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn (string $v) => $v !== '')
            ->values()
            ->all();

        if (empty($variablePaths)) {
            return $refs;
        }

        $mergedPaths = array_values(array_unique(array_merge($paths, $variablePaths)));
        $selectedAssets = is_array($refs['selected_assets'] ?? null) ? $refs['selected_assets'] : [];
        foreach ($variablePaths as $path) {
            $selectedAssets[] = [
                'id' => null,
                'path' => $path,
                'original_name' => 'asset_variable',
                'mime' => '',
                'ref_number' => null,
            ];
        }

        $refs['selected_paths'] = $mergedPaths;
        $refs['selected_assets'] = $selectedAssets;
        $refs['selection_mode'] = (string) (($refs['selection_mode'] ?? 'none') === 'none'
            ? 'asset_variable'
            : ((string) $refs['selection_mode'] . '+asset_variable'));
        $refs['selected_ids'] = array_values(array_unique(array_filter(array_map(
            fn ($v) => (int) $v,
            (array) ($refs['selected_ids'] ?? [])
        ), fn ($v) => $v > 0)));

        if (!empty($mergedPaths)) {
            $refs['primary_preference'] = [
                'path' => (string) $mergedPaths[0],
                'reason' => 'asset_variable_selection',
                'confidence' => 1.0,
            ];
        }

        return $refs;
    }

    private function buildSourceRefsFromAssetVariables(array $refs): array
    {
        $resolved = is_array($refs['resolved'] ?? null) ? $refs['resolved'] : [];
        $out = [];

        foreach ($resolved as $variable) {
            if (!is_array($variable)) {
                continue;
            }
            $out[] = [
                'type' => 'asset_variable',
                'variable_id' => isset($variable['id']) ? (int) $variable['id'] : null,
                'name' => (string) ($variable['name'] ?? ''),
                'slug' => (string) ($variable['slug'] ?? ''),
                'kind' => (string) ($variable['kind'] ?? 'custom'),
                'asset_paths' => array_values(array_filter(array_map(
                    'strval',
                    (array) ($variable['asset_paths'] ?? [])
                ))),
            ];
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private function buildImageDirectionWithVariables(string $brief, array $assetVariableRefs, array $assetIdentity = []): string
    {
        $base = 'Visual coerente con il brand e con questo brief: ' . Str::limit($brief, 220, '');
        $resolved = is_array($assetVariableRefs['resolved'] ?? null) ? $assetVariableRefs['resolved'] : [];
        if (empty($resolved)) {
            return $this->appendAssetIdentityDirections($base, $assetIdentity);
        }

        $labels = [];
        foreach ($resolved as $variable) {
            if (!is_array($variable)) {
                continue;
            }
            $name = trim((string) ($variable['name'] ?? ''));
            $kind = trim((string) ($variable['kind'] ?? 'custom'));
            if ($name === '') {
                continue;
            }
            $labels[] = $name . ' [' . $kind . ']';
        }

        if (empty($labels)) {
            return $this->appendAssetIdentityDirections($base, $assetIdentity);
        }

        $direction = $base . '. Variabili oggetto da rispettare: ' . implode(', ', array_slice($labels, 0, 6)) . '.';

        $hasLocationEnvelope = collect($resolved)->contains(function ($variable): bool {
            if (!is_array($variable)) {
                return false;
            }

            $kind = Str::lower(trim((string) ($variable['kind'] ?? 'custom')));
            if ($kind === 'location') {
                return true;
            }

            $text = Str::lower(trim(
                (string) ($variable['name'] ?? '') . ' ' . (string) ($variable['description'] ?? '')
            ));

            return str_contains($text, 'ufficio')
                || str_contains($text, 'edificio')
                || str_contains($text, 'showroom')
                || str_contains($text, 'negozio')
                || str_contains($text, 'locale');
        });

        if ($hasLocationEnvelope) {
            $direction .= ' Se nei riferimenti c e un luogo reale, quello resta l involucro principale: mantieni riconoscibili struttura, ambientazione e dettagli distintivi, aggiungendo creativita solo negli elementi secondari.';
        }

        return $this->appendAssetIdentityDirections($direction, $assetIdentity);
    }

    /**
     * Traduce gli slot presenter/product/place in un payload chiaro per il job AI.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $assetVariableRefs
     * @return array<string, mixed>
     */
    private function buildAssetIdentityContext(array $data, array $assetVariableRefs): array
    {
        $resolvedRows = collect(is_array($assetVariableRefs['resolved'] ?? null) ? $assetVariableRefs['resolved'] : []);
        $slotMap = [
            'presenter' => (int) ($data['presenter_variable_id'] ?? 0),
            'product' => (int) ($data['product_variable_id'] ?? 0),
            'place' => (int) ($data['place_variable_id'] ?? 0),
        ];

        $slots = [];
        $lockedElements = [];
        $allowedChanges = [];

        foreach ($slotMap as $slot => $variableId) {
            if ($variableId < 1) {
                continue;
            }

            $row = $resolvedRows->first(fn ($variable) => (int) ($variable['id'] ?? 0) === $variableId);
            if (!is_array($row)) {
                continue;
            }

            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
            $locked = trim((string) data_get($profile, 'prompt_lock.immutable_elements', data_get($profile, 'immutable_traits', '')));
            if ($locked !== '') {
                $lockedElements[] = $locked;
            }

            foreach ((array) data_get($profile, 'allowed_transforms', []) as $transform) {
                $transform = trim((string) $transform);
                if ($transform !== '') {
                    $allowedChanges[] = $transform;
                }
            }

            $voiceAssetPath = trim((string) ($row['voice_asset_path'] ?? data_get($profile, 'voice_reference.sample_path', '')));
            $voiceAssetName = trim((string) ($row['voice_asset_name'] ?? data_get($profile, 'voice_reference.sample_name', '')));
            $voiceProvider = trim((string) ($row['voice_provider'] ?? data_get($profile, 'voice_reference.provider', '')));
            $voiceProviderVoiceId = trim((string) ($row['voice_provider_voice_id'] ?? data_get($profile, 'voice_reference.provider_voice_id', '')));
            $voiceStatus = trim((string) ($row['voice_status'] ?? data_get($profile, 'voice_reference.status', '')));

            $slots[$slot] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'kind' => (string) ($row['kind'] ?? 'custom'),
                'asset_role' => (string) ($row['asset_role'] ?? ''),
                'canonical_asset_id' => isset($row['canonical_asset_id']) ? (int) $row['canonical_asset_id'] : null,
                'canonical_asset_path' => (string) ($row['canonical_asset_path'] ?? ''),
                'identity_mode' => (string) ($row['identity_mode'] ?? 'balanced'),
                'consistency_threshold' => isset($row['consistency_threshold']) ? (int) $row['consistency_threshold'] : null,
                'voice_asset_id' => isset($row['voice_asset_id']) ? (int) $row['voice_asset_id'] : null,
                'voice_asset_path' => $voiceAssetPath,
                'voice_asset_name' => $voiceAssetName,
                'voice_provider' => $voiceProvider,
                'voice_provider_voice_id' => $voiceProviderVoiceId,
                'voice_status' => $voiceStatus,
                'voice_label' => (string) data_get($profile, 'voice_reference.label', ''),
                'locked_elements' => $locked !== '' ? [$locked] : [],
                'allowed_transforms' => array_values(array_filter(array_map('strval', (array) data_get($profile, 'allowed_transforms', [])))),
                'descriptor' => [
                    'summary' => (string) data_get($profile, 'descriptor.summary', data_get($profile, 'identity_summary', '')),
                ],
            ];
        }

        $seasonalOverlay = trim((string) ($data['seasonal_overlay'] ?? ''));
        if ($seasonalOverlay !== '') {
            $allowedChanges[] = $seasonalOverlay;
        }

        return [
            'slots' => $slots,
            'slot_ids' => array_values(array_map(fn ($row) => (int) ($row['id'] ?? 0), $slots)),
            'seasonal_overlay' => $seasonalOverlay,
            'consistency_mode' => $this->assetIdentityService->normalizeIdentityMode((string) ($data['consistency_mode'] ?? 'balanced')),
            'locked_elements' => array_values(array_unique(array_filter($lockedElements))),
            'allowed_changes' => array_values(array_unique(array_filter($allowedChanges))),
        ];
    }
    private function buildSourceRefsFromAssetIdentity(array $assetIdentity): array
    {
        $slots = is_array($assetIdentity['slots'] ?? null) ? $assetIdentity['slots'] : [];
        $out = [];

        foreach ($slots as $slot => $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'type' => 'asset_identity_slot',
                'slot' => (string) $slot,
                'variable_id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => (string) ($row['name'] ?? ''),
                'kind' => (string) ($row['kind'] ?? 'custom'),
                'canonical_asset_path' => (string) ($row['canonical_asset_path'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Aggiunge al brief visuale i nuovi vincoli persistenti presenter/product/place.
     *
     * @param  array<string, mixed>  $assetIdentity
     */
    private function appendAssetIdentityDirections(string $direction, array $assetIdentity): string
    {
        $slots = is_array($assetIdentity['slots'] ?? null) ? $assetIdentity['slots'] : [];
        $consistencyMode = trim((string) ($assetIdentity['consistency_mode'] ?? ''));
        $seasonalOverlay = trim((string) ($assetIdentity['seasonal_overlay'] ?? ''));
        $lockedElements = array_values(array_filter(array_map('strval', (array) ($assetIdentity['locked_elements'] ?? []))));
        $allowedChanges = array_values(array_filter(array_map('strval', (array) ($assetIdentity['allowed_changes'] ?? []))));

        if (!empty($slots)) {
            $slotLabels = [];
            foreach ($slots as $slot => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $slotLabels[] = $slot . ': ' . $name;
            }

            if (!empty($slotLabels)) {
                $direction .= ' Slot identitari selezionati: ' . implode(', ', $slotLabels) . '.';
            }
        }

        if ($consistencyMode !== '') {
            $direction .= ' Modalita coerenza: ' . $consistencyMode . '.';
        }
        if ($seasonalOverlay !== '') {
            $direction .= ' Overlay o tema da applicare senza cambiare il soggetto base: ' . Str::limit($seasonalOverlay, 120, '') . '.';
        }
        if (!empty($lockedElements)) {
            $direction .= ' Elementi da non alterare: ' . implode('; ', array_slice($lockedElements, 0, 4)) . '.';
        }
        if (!empty($allowedChanges)) {
            $direction .= ' Cambi ammessi: ' . implode(', ', array_slice($allowedChanges, 0, 6)) . '.';
        }

        return $direction;
    }

    private function extractReferenceNumbersFromBrief(string $brief, int $maxNumber): array
    {
        if ($maxNumber < 1) {
            return [];
        }

        $numbers = [];
        $push = function (string|int $value) use (&$numbers, $maxNumber): void {
            $n = (int) $value;
            if ($n < 1 || $n > $maxNumber) {
                return;
            }
            $numbers[] = $n;
        };

        if (preg_match_all('/#\s*(\d{1,3})/u', $brief, $mHash)) {
            foreach ((array) ($mHash[1] ?? []) as $raw) {
                $push((string) $raw);
            }
        }

        if (preg_match_all('/\b(?:n|num|numero)\s*\.?\s*(\d{1,3})\b/iu', $brief, $mNum)) {
            foreach ((array) ($mNum[1] ?? []) as $raw) {
                $push((string) $raw);
            }
        }

        if (
            preg_match_all(
                '/\b(?:foto|fotos|immagine|immagini|image|images|asset|assets)\s*(?:n(?:um(?:ero)?)?\.?\s*)?((?:\d{1,3}\s*(?:,|;|\/|\\\\|\||e|ed|-)?\s*){1,8})/iu',
                $brief,
                $mGroups
            )
        ) {
            foreach ((array) ($mGroups[1] ?? []) as $chunk) {
                if (!is_string($chunk) || trim($chunk) === '') {
                    continue;
                }

                if (preg_match_all('/\d{1,3}/', $chunk, $mInline)) {
                    foreach ((array) ($mInline[0] ?? []) as $raw) {
                        $push((string) $raw);
                    }
                }
            }
        }

        return array_values(array_unique($numbers));
    }

    private function buildBrandReferences(array $profileData, array $assets, array $assetVariables = []): array
    {
        $logo = null;
        $images = [];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['kind'] ?? null) === 'logo' && $logo === null && !empty($asset['path'])) {
                $logo = (string) $asset['path'];
            }
            if (($asset['kind'] ?? null) === 'image' && !empty($asset['path'])) {
                $images[] = (string) $asset['path'];
            }
        }

        return [
            'business_name' => $profileData['business_name'] ?? null,
            'palette' => $profileData['brand_palette'] ?? null,
            'logo_path' => $logo,
            'reference_images' => array_values(array_unique($images)),
            'asset_variables' => array_values($assetVariables),
        ];
    }

    private function resolvePlanForSingleItem(
        int $tenantId,
        int $userId,
        Carbon $scheduledAt,
        array $strategy
    ): ContentPlan {
        $date = $scheduledAt->toDateString();

        $matchingPlan = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->latest('id')
            ->first();

        if ($matchingPlan) {
            return $matchingPlan;
        }

        $latestPlan = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();

        if ($latestPlan) {
            return $latestPlan;
        }

        return ContentPlan::query()->create([
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'name' => 'Piano singolo ' . $scheduledAt->format('d/m'),
            'start_date' => $date,
            'end_date' => $date,
            'status' => 'draft',
            'settings' => [
                'goal' => 'Contenuto singolo on demand',
                'tone' => 'professionale',
                'posts_total' => 1,
                'platforms' => ['instagram'],
                'formats' => ['post'],
                'mode' => 'single_manual',
            ],
            'strategy' => $strategy,
        ]);
    }

    private function selectPreferredBrandImage(string $brief, array $assets): ?array
    {
        $images = collect($assets)
            ->filter(fn ($a) => is_array($a) && (($a['kind'] ?? null) === 'image') && !empty($a['path']))
            ->values();

        if ($images->isEmpty()) {
            return null;
        }

        $normalizedBrief = $this->normalizeSimpleText($brief);
        if ($normalizedBrief === '') {
            return null;
        }

        if (
            str_contains($normalizedBrief, 'ultima immagine')
            || str_contains($normalizedBrief, 'ultima foto')
            || str_contains($normalizedBrief, 'latest image')
            || str_contains($normalizedBrief, 'last image')
        ) {
            $latest = $images->first();
            return [
                'path' => (string) ($latest['path'] ?? ''),
                'reason' => 'manual_latest_image_hint',
                'confidence' => 1.0,
            ];
        }

        $tokens = $this->extractMeaningfulTokens($normalizedBrief);
        if (empty($tokens)) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($images as $img) {
            $name = (string) ($img['original_name'] ?? '');
            $path = (string) ($img['path'] ?? '');
            $haystack = $this->normalizeSimpleText($name . ' ' . basename($path));

            if ($haystack === '') {
                continue;
            }

            $score = 0;
            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $img;
            }
        }

        if ($best && $bestScore >= 2) {
            return [
                'path' => (string) ($best['path'] ?? ''),
                'reason' => 'manual_brief_keyword_match',
                'confidence' => min(0.95, 0.45 + ($bestScore * 0.08)),
            ];
        }

        return null;
    }

    private function normalizeSimpleText(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    private function extractMeaningfulTokens(string $normalized): array
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

    /**
     * @param  array{scheduled:int,warnings:array<int,string>}|null  $publicationSync
     */
    private function publicationSyncMessage(?array $publicationSync): string
    {
        if (!is_array($publicationSync)) {
            return '';
        }

        $parts = [];
        $scheduled = (int) ($publicationSync['scheduled'] ?? 0);
        $warnings = array_values(array_filter((array) ($publicationSync['warnings'] ?? [])));

        if ($scheduled > 0) {
            $parts[] = "Pubblicazioni Meta pianificate: {$scheduled}.";
        }

        if (!empty($warnings)) {
            $parts[] = 'Attenzione: ' . implode(' ', $warnings);
        }

        return trim(implode(' ', $parts));
    }

    private function allowsCustomImageProvider(ContentItem $contentItem): bool
    {
        $meta = is_array($contentItem->ai_meta) ? $contentItem->ai_meta : [];
        $source = trim((string) data_get($meta, 'source', ''));
        if ($source === 'manual_single_content') {
            return true;
        }

        $mode = trim((string) data_get($meta, 'plan.mode', ''));
        if ($mode === 'single_manual') {
            return true;
        }

        return trim((string) data_get($contentItem->plan?->settings, 'mode', '')) === 'single_manual';
    }

    private function estimateGenerationSeconds(ContentItem $contentItem): int
    {
        $format = Str::lower(trim((string) ($contentItem->format ?? 'post')));
        $meta = is_array($contentItem->ai_meta) ? $contentItem->ai_meta : [];
        $videoProvider = Str::lower(trim((string) data_get($meta, 'video_provider', config('generation.video_provider_default', 'openai'))));
        $imageProvider = Str::lower(trim((string) data_get($meta, 'image_provider', config('generation.image_provider_default', 'nanobanana'))));

        if ($format === 'reel') {
            return match ($videoProvider) {
                'runway' => 150,
                'kling' => 175,
                default => 190,
            };
        }

        if ($format === 'story') {
            return match ($videoProvider) {
                'runway' => 120,
                'kling' => 145,
                default => 150,
            };
        }

        return $imageProvider === 'openai' ? 40 : 60;
    }

    /**
     * @return array<int, string>
     */
    private function generationStages(ContentItem $contentItem): array
    {
        $format = Str::lower(trim((string) ($contentItem->format ?? 'post')));

        if (in_array($format, ['reel', 'story'], true)) {
            return [
                'Analisi del brief e della strategia',
                'Scrittura del copy e della direzione video',
                'Generazione visuale e rifinitura finale',
            ];
        }

        return [
            'Analisi brand e materiali reali',
            'Scrittura caption e prompt visuale',
            'Generazione immagine e controllo finale',
        ];
    }

    private function normalizeCreatePreset(string $preset): string
    {
        $preset = Str::lower(trim($preset));

        return $preset === 'reel' ? 'reel' : 'default';
    }

}



