<?php

namespace App\Http\Controllers;

use App\Models\AlterEgo;
use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\EditorialStrategy;
use App\Models\TenantProfile;
use App\Services\AssetIdentityService;
use App\Services\AssetVariableService;
use App\Services\BrandScraperService;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\Editorial\TrendBriefService;
use App\Services\GuidedAssetVariableService;
use App\Services\Learning\TenantLearningLoopService;
use App\Services\TenantQuotaService;
use App\Support\SpeechProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TenantProfileController extends Controller
{
    public function __construct(
        private readonly EditorialStrategyService $editorialStrategyService,
        private readonly TrendBriefService $trendBriefService,
        private readonly AssetIdentityService $assetIdentityService,
        private readonly AssetVariableService $assetVariableService,
        private readonly GuidedAssetVariableService $guidedAssetVariableService,
        private readonly TenantLearningLoopService $tenantLearningLoopService,
        private readonly TenantQuotaService $tenantQuotaService
    ) {
    }

    /**
     * Estrae i dati brand da un URL pubblico e li restituisce come JSON
     * per pre-compilare il form di onboarding lato client.
     *
     * POST /profile/brand/scrape-url
     * Body: { url: "https://example.com" }
     *
     * Risponde sempre con 200; l'errore è nel payload JSON (campo `error`).
     */
    public function scrapeUrl(Request $request, BrandScraperService $brandScraper): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
        ]);

        $result = $brandScraper->scrapeFromUrl($data['url']);

        return response()->json($result);
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $brandWarnings = [];

        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $strategy = null;

        try {
            $strategy = $this->editorialStrategyService->forTenant($tenantId);
            if (!$strategy && $profile) {
                $strategy = $this->editorialStrategyService->refreshForTenant($tenantId, $profile);
            }
        } catch (\Throwable $e) {
            Log::warning('brand_center.strategy_unavailable', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);
            $brandWarnings[] = 'Strategia e trend non disponibili al momento: ' . $e->getMessage();
        }

        try {
            $learningProfile = is_array($profile?->learning_preferences) && !empty($profile->learning_preferences)
                ? (array) $profile->learning_preferences
                : ((bool) config('social_manager.features.tenant_learning_v1', true)
                    ? $this->tenantLearningLoopService->refreshForTenant($tenantId)
                    : []);
        } catch (\Throwable $e) {
            Log::warning('brand_center.learning_unavailable', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);
            $learningProfile = is_array($profile?->learning_preferences) ? (array) $profile->learning_preferences : [];
            $brandWarnings[] = 'Learning profile non aggiornato: ' . $e->getMessage();
        }

        try {
            $trendBrief = (bool) config('social_manager.features.trend_brief_v1', true)
                ? $this->trendBriefService->getBriefForTenant(
                    $tenantId,
                    $profile,
                    $strategy?->toArray() ?? [],
                    [
                        'strategy' => $strategy?->toArray() ?? [],
                        'learning_preferences' => $learningProfile,
                        'platforms' => (array) ($profile?->default_platforms ?? ['instagram']),
                        'formats' => (array) ($profile?->default_formats ?? ['post']),
                    ]
                )
                : [];
        } catch (\Throwable $e) {
            Log::warning('brand_center.trend_brief_unavailable', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);
            $trendBrief = [];
            $brandWarnings[] = 'Trend Brief non disponibile: ' . $e->getMessage();
        }

        $assets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->get()
            ->map(fn (BrandAsset $asset) => $this->sanitizeAssetForDisplay($asset));

        $assetVariables = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->latest('id')
            ->get();

        $assetVariableCatalog = $this->sanitizeRecursiveStrings(
            $this->assetVariableService->catalogForTenant($tenantId)
        );
        $alterEgos = AlterEgo::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'archetype', 'is_default', 'visual_references']);

        $imageProviderOptions = \App\Support\ImageProviderResolver::configuredWithLabels();
        $videoProviderOptions = \App\Support\VideoProviderResolver::configuredWithLabels();

        return view('wizard.brand', compact(
            'profile',
            'assets',
            'strategy',
            'trendBrief',
            'learningProfile',
            'assetVariables',
            'assetVariableCatalog',
            'brandWarnings',
            'alterEgos',
            'imageProviderOptions',
            'videoProviderOptions'
        ));
    }

    public function refreshTrendBrief(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        try {
            $strategy = $this->editorialStrategyService->forTenant($tenantId);
            $learningProfile = (bool) config('social_manager.features.tenant_learning_v1', true)
                ? $this->tenantLearningLoopService->refreshForTenant($tenantId)
                : [];

            $this->trendBriefService->getBriefForTenant(
                $tenantId,
                $profile,
                $strategy?->toArray() ?? [],
                [
                    'strategy' => $strategy?->toArray() ?? [],
                    'learning_preferences' => $learningProfile,
                    'platforms' => (array) ($profile?->default_platforms ?? ['instagram']),
                    'formats' => (array) ($profile?->default_formats ?? ['post']),
                    'force_refresh' => true,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('brand_center.trend_refresh_failed', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('profile.brand')
                ->with('status', 'Trend Brief non aggiornato: ' . $e->getMessage());
        }

        return redirect()
            ->route('profile.brand')
            ->with('status', 'Trend Brief aggiornato.');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $existingProfile = TenantProfile::query()->where('tenant_id', $tenantId)->first();

        $data = $request->validate([
            'business_name' => 'required|string|max:120',
            'industry' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'vision' => 'nullable|string|max:2000',
            'values' => 'nullable|string|max:2000',
            'business_hours' => 'nullable|string|max:1000',
            'seasonal_offers' => 'nullable|string|max:2000',
            'brand_palette' => 'nullable|string|max:255',
            'overlay_font_preset' => 'nullable|string|max:40',
            'overlay_tone_preset' => 'nullable|string|max:40',
            'overlay_font_family' => 'nullable|string|max:60',
            'overlay_font_fallback' => 'nullable|string|max:60',
            'overlay_preset' => 'nullable|string|max:80',
            'overlay_safe_area' => 'nullable|string|max:40',
            'overlay_auto_enabled' => 'nullable|boolean',
            'preferred_hook_intensity' => 'nullable|string|max:20',
            'trend_appetite' => 'nullable|string|max:20',
            'professionalism_guardrail_level' => 'nullable|string|max:20',

            'services' => 'nullable|string|max:2000',
            'target' => 'nullable|string|max:2000',
            'cta' => 'nullable|string|max:255',

            'default_goal' => 'nullable|string|max:500',
            'default_tone' => 'nullable|string|max:80',
            'default_posts_per_week' => 'nullable|integer|min:1|max:21',
            'default_platforms' => 'nullable|array',
            'default_platforms.*' => 'string|max:50',
            'default_formats' => 'nullable|array',
            'default_formats.*' => 'string|max:50',

            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:4096',
            'videos' => 'nullable|array',
            'videos.*' => 'file|mimes:mp4,mov,webm|max:51200',
            'audios' => 'nullable|array',
            'audios.*' => 'file|mimes:mp3,wav,m4a,ogg,webm|max:20480',
            'documents' => 'nullable|array|max:12',
            'documents.*' => 'file|mimetypes:application/pdf,text/plain,text/csv,text/html,text/markdown,application/json,application/xml,text/xml,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation|max:20480',
            'asset_upload_notes' => 'nullable|string|max:1200',
            'knowledge_text_title' => 'nullable|string|max:160',
            'knowledge_text_body' => 'nullable|string|max:6000',
            'knowledge_text_notes' => 'nullable|string|max:1200',
            'reference_link_label' => 'nullable|string|max:160',
            'reference_link_url' => 'nullable|url|max:1500',
            'reference_link_notes' => 'nullable|string|max:1200',

            'strategy_action' => 'nullable|string|in:save,regenerate',
            'strategy_locked' => 'nullable|boolean',
            'strategy_goal_primary' => 'nullable|string|max:220',
            'strategy_goal_secondary' => 'nullable|string|max:220',
            'strategy_kpi_primary' => 'nullable|string|max:220',
            'strategy_kpi_secondary' => 'nullable|string|max:220',
            'strategy_audience_focus' => 'nullable|string|max:500',
            'strategy_offer_focus' => 'nullable|string|max:500',
            'strategy_visual_style' => 'nullable|string|max:300',
            'strategy_visual_mood' => 'nullable|string|max:300',
            'strategy_visual_palette' => 'nullable|string|max:500',
            'strategy_palette_mode' => 'nullable|string|max:80',
            'strategy_logo_rule' => 'nullable|string|max:300',
            'strategy_visual_do' => 'nullable|string|max:500',
            'strategy_visual_dont' => 'nullable|string|max:500',
            'strategy_posts_per_week' => 'nullable|integer|min:1|max:31',
            'strategy_best_days' => 'nullable|string|max:220',
            'strategy_best_times' => 'nullable|string|max:220',
            'strategy_channel_priority' => 'nullable|string|max:300',
            'strategy_format_priority' => 'nullable|string|max:300',
            'strategy_cadence_rule' => 'nullable|string|max:500',
            'strategy_notes' => 'nullable|string|max:3000',
        ]);

        DB::beginTransaction();
        try {
            $existingOverlayPreferences = is_array($existingProfile?->overlay_preferences)
                ? $existingProfile->overlay_preferences
                : [];
            $fontPreset = (string) (
                $data['overlay_font_preset']
                ?? $data['overlay_tone_preset']
                ?? $existingOverlayPreferences['font_preset']
                ?? $existingOverlayPreferences['tone_preset']
                ?? 'modern'
            );
            $profile = TenantProfile::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'business_name' => $data['business_name'],
                    'industry' => $data['industry'] ?? null,
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'vision' => $data['vision'] ?? null,
                    'values' => $data['values'] ?? null,
                    'business_hours' => $data['business_hours'] ?? null,
                    'seasonal_offers' => $data['seasonal_offers'] ?? null,
                    'brand_palette' => $data['brand_palette'] ?? null,
                    'overlay_preferences' => array_merge($existingOverlayPreferences, [
                        'font_preset' => $fontPreset,
                        'tone_preset' => $fontPreset,
                        'font_family' => $data['overlay_font_family'] ?? 'arial',
                        'fallback_font_family' => $data['overlay_font_fallback'] ?? 'segoe_ui',
                        'preset' => $data['overlay_preset'] ?? 'modern_split_caption',
                        'safe_area' => $data['overlay_safe_area'] ?? 'upper_third',
                        'auto_enabled' => (bool) ($data['overlay_auto_enabled'] ?? true),
                        'preferred_hook_intensity' => $data['preferred_hook_intensity'] ?? 'medium',
                        'trend_appetite' => $data['trend_appetite'] ?? 'medium',
                        'professionalism_guardrail_level' => $data['professionalism_guardrail_level'] ?? 'high',
                    ]),
                    'services' => $data['services'] ?? null,
                    'target' => $data['target'] ?? null,
                    'cta' => $data['cta'] ?? null,
                    'default_goal' => $data['default_goal'] ?? null,
                    'default_tone' => $data['default_tone'] ?? null,
                    'default_posts_per_week' => $data['default_posts_per_week'] ?? null,
                    'default_platforms' => $data['default_platforms'] ?? [],
                    'default_formats' => $data['default_formats'] ?? [],
                    'completed_at' => Carbon::now(),
                    'onboarding_started_at' => $existingProfile?->onboarding_started_at ?? Carbon::now(),
                    'onboarding_completed_at' => $existingProfile?->onboarding_completed_at ?? Carbon::now(),
                    'quickstart_generated_at' => $existingProfile?->quickstart_generated_at,
                    'quickstart_last_plan_id' => $existingProfile?->quickstart_last_plan_id,
                    'quickstart_dismissed_at' => $existingProfile?->quickstart_dismissed_at,
                ]
            );

            $baseDir = 'brand-assets/' . $tenantId;
            $assetUploadNotes = trim((string) ($data['asset_upload_notes'] ?? ''));

            if ($request->hasFile('logo')) {
                $this->storeBrandAssetUpload(
                    tenantId: $tenantId,
                    file: $request->file('logo'),
                    kind: 'logo',
                    directory: $baseDir . '/logo',
                    notes: $assetUploadNotes
                );
            }

            if ($request->hasFile('images')) {
                foreach ((array) $request->file('images') as $img) {
                    $this->storeBrandAssetUpload(
                        tenantId: $tenantId,
                        file: $img,
                        kind: 'image',
                        directory: $baseDir . '/images',
                        notes: $assetUploadNotes
                    );
                }
            }

            if ($request->hasFile('videos')) {
                foreach ((array) $request->file('videos') as $video) {
                    $this->storeBrandAssetUpload(
                        tenantId: $tenantId,
                        file: $video,
                        kind: 'video',
                        directory: $baseDir . '/videos',
                        notes: $assetUploadNotes
                    );
                }
            }

            if ($request->hasFile('audios')) {
                foreach ((array) $request->file('audios') as $audio) {
                    $this->storeBrandAssetUpload(
                        tenantId: $tenantId,
                        file: $audio,
                        kind: 'audio',
                        directory: $baseDir . '/audio',
                        notes: $assetUploadNotes
                    );
                }
            }

            if ($request->hasFile('documents')) {
                foreach ((array) $request->file('documents') as $document) {
                    $this->storeBrandAssetUpload(
                        tenantId: $tenantId,
                        file: $document,
                        kind: 'document',
                        directory: $baseDir . '/documents',
                        notes: $assetUploadNotes,
                        meta: $this->buildDocumentAssetMeta($document)
                    );
                }
            }

            $this->storeBrandKnowledgeText(
                tenantId: $tenantId,
                title: (string) ($data['knowledge_text_title'] ?? ''),
                body: (string) ($data['knowledge_text_body'] ?? ''),
                notes: (string) ($data['knowledge_text_notes'] ?? '')
            );

            $this->storeBrandLinkReference(
                tenantId: $tenantId,
                label: (string) ($data['reference_link_label'] ?? ''),
                url: (string) ($data['reference_link_url'] ?? ''),
                notes: (string) ($data['reference_link_notes'] ?? '')
            );

            $strategyAction = (string) ($data['strategy_action'] ?? 'save');
            $currentStrategy = $this->editorialStrategyService->forTenant($tenantId);
            $wasLocked = (bool) ($currentStrategy?->is_locked ?? false);
            $requestedLock = (bool) ($data['strategy_locked'] ?? $wasLocked);
            $forceRefresh = $strategyAction === 'regenerate';
            $shouldAutoRefresh = !$wasLocked || !$requestedLock || $forceRefresh || !$currentStrategy;

            $strategy = $shouldAutoRefresh
                ? $this->editorialStrategyService->refreshForTenant(
                    tenantId: $tenantId,
                    profile: $profile,
                    overrides: [],
                    force: $forceRefresh
                )
                : $currentStrategy;

            if (!$strategy) {
                $strategy = $this->editorialStrategyService->refreshForTenant(
                    tenantId: $tenantId,
                    profile: $profile,
                    overrides: [],
                    force: true
                );
            }

            $studioPayload = $this->extractStudioPayload($data, $profile, $strategy);
            $this->editorialStrategyService->applyStudioInputs($strategy, $studioPayload);

            DB::commit();

            $status = 'Profilo attivita e strategia salvati';
            if ($forceRefresh) {
                $status .= ' (strategia rigenerata)';
            } elseif (!$shouldAutoRefresh) {
                $status .= ' (auto-rigenerazione bloccata da lock)';
            }

            return redirect()->route('profile.brand')->with('status', $status);
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('profile.brand')->with('status', 'Errore salvataggio: ' . $e->getMessage());
        }
    }

    public function destroyAssets(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
        ]);

        $assets = BrandAsset::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('content_plan_id')
            ->whereIn('id', $data['asset_ids'])
            ->get();

        $assetIds = $assets
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        DB::transaction(function () use ($assets, $user, $assetIds): void {
            $this->detachAssetsFromVariables((int) $user->tenant_id, $assetIds);

            foreach ($assets as $asset) {
                if ($asset->path) {
                    Storage::disk('public')->delete($asset->path);
                }
                $asset->delete();
            }
        });

        return redirect()->route('profile.brand')->with('status', 'Assets selezionati eliminati');
    }

    /**
     * Delete a single tenant-level asset.
     */
    public function destroyAsset(Request $request, BrandAsset $asset)
    {
        $user = $request->user();

        if ((int) $asset->tenant_id !== (int) $user->tenant_id || !is_null($asset->content_plan_id)) {
            abort(403);
        }

        DB::transaction(function () use ($asset, $user): void {
            $this->detachAssetsFromVariables((int) $user->tenant_id, [(int) $asset->id]);

            if ($asset->path) {
                Storage::disk('public')->delete($asset->path);
            }

            $asset->delete();
        });

        return redirect()->route('profile.brand')->with('status', 'Asset eliminato');
    }
    public function storeVariable(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'kind' => 'required|string|in:person,location,product,custom',
            'asset_role' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:1000',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'integer',
            'canonical_asset_id' => 'nullable|integer',
            'identity_mode' => 'nullable|string|in:strict,balanced,creative',
            'consistency_threshold' => 'nullable|integer|min:50|max:99',
            'descriptor_summary' => 'nullable|string|max:1000',
            'immutable_elements' => 'nullable|string|max:1200',
            'allowed_transforms' => 'nullable|string|max:2000',
            'visual_tags' => 'nullable|string|max:1200',
            'positive_examples' => 'nullable|string|max:1600',
            'negative_examples' => 'nullable|string|max:1600',
            'prompt_notes' => 'nullable|string|max:1200',
            'usage_notes' => 'nullable|string|max:1200',
        ]);

        $assetIds = $this->assetVariableService->sanitizeAssetIdsForTenant($tenantId, (array) ($data['asset_ids'] ?? []));
        if (empty($assetIds)) {
            return redirect()
                ->route('profile.brand')
                ->with('status', 'Seleziona almeno 1 asset valido per creare la variabile.');
        }

        $canonicalAssetId = (int) ($data['canonical_asset_id'] ?? 0);
        if ($canonicalAssetId > 0 && !in_array($canonicalAssetId, $assetIds, true)) {
            return redirect()
                ->route('profile.brand')
                ->withInput()
                ->with('status', 'L asset canonico deve far parte degli asset selezionati.');
        }
        if ($canonicalAssetId < 1) {
            $canonicalAssetId = (int) ($assetIds[0] ?? 0);
        }

        $slug = $this->assetVariableService->buildUniqueSlugForTenant($tenantId, (string) $data['name']);
        $linkedAssets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->whereIn('id', $assetIds)
            ->get();

        // Le regole identitarie servono al job AI per distinguere cosa resta fisso e cosa puo cambiare.
        $profile = $this->assetIdentityService->buildManualProfile($data, $linkedAssets);
        $profile['role'] = $this->assetIdentityService->normalizeRole((string) $data['kind'], (string) ($data['asset_role'] ?? ''));
        $profile['identity_mode'] = $this->assetIdentityService->normalizeIdentityMode((string) ($data['identity_mode'] ?? 'balanced'));
        $profile['consistency_threshold'] = $this->assetIdentityService->normalizeConsistencyThreshold(
            $data['consistency_threshold'] ?? null
        );
        $identityPack = $this->assetIdentityService->buildManualIdentityPack($data, $linkedAssets);

        $variable = AssetVariable::query()->create([
            'tenant_id' => $tenantId,
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'kind' => (string) $data['kind'],
            'asset_role' => $profile['role'],
            'description' => trim((string) ($data['description'] ?? '')),
            'asset_ids' => $assetIds,
            'canonical_asset_id' => $canonicalAssetId > 0 ? $canonicalAssetId : null,
            'identity_mode' => $profile['identity_mode'],
            'consistency_threshold' => $profile['consistency_threshold'],
            'profile' => $profile,
            'identity_pack' => $identityPack,
            'is_active' => true,
        ]);

        $this->assetIdentityService->syncAssetMetaForVariable($variable, $assetIds);

        return redirect()->route('profile.brand')->with('status', 'Identita asset creata e collegata al Brand Center.');
    }

    private function storeBrandAssetUpload(
        int $tenantId,
        ?UploadedFile $file,
        string $kind,
        string $directory,
        string $notes = '',
        array $meta = []
    ): ?BrandAsset {
        if (!$file instanceof UploadedFile) {
            return null;
        }

        $path = $file->store($directory, 'public');
        $meta = array_merge([
            'source' => 'brand_center',
            'ingestion_scope' => 'tenant_library',
        ], $meta);

        if ($notes !== '') {
            $meta['grounding_notes'] = $notes;
        }

        return BrandAsset::query()->create([
            'tenant_id' => $tenantId,
            'content_plan_id' => null,
            'kind' => $kind,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'meta' => $meta,
        ]);
    }

    private function storeBrandKnowledgeText(
        int $tenantId,
        string $title,
        string $body,
        string $notes = ''
    ): ?BrandAsset {
        $body = $this->normalizeInlineText($body);
        if ($body === '') {
            return null;
        }

        $title = trim($title);
        $excerpt = $this->buildKnowledgeExcerpt($body, 420);
        $meta = [
            'source' => 'brand_center',
            'ingestion_scope' => 'tenant_library',
            'content_origin' => 'manual_text_entry',
            'text_title' => $title,
            'knowledge_text' => $this->buildKnowledgeExcerpt($body, 2400),
            'text_excerpt' => $excerpt,
        ];

        if ($notes = $this->normalizeInlineText($notes)) {
            $meta['grounding_notes'] = $notes;
        }

        return BrandAsset::query()->create([
            'tenant_id' => $tenantId,
            'content_plan_id' => null,
            'kind' => 'text',
            'path' => '',
            'original_name' => Str::limit($title !== '' ? $title : $excerpt, 180, ''),
            'size' => strlen($body),
            'mime' => 'text/plain',
            'meta' => $meta,
        ]);
    }

    private function storeBrandLinkReference(
        int $tenantId,
        string $label,
        string $url,
        string $notes = ''
    ): ?BrandAsset {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $label = trim($label);
        if ($label === '') {
            $host = parse_url($url, PHP_URL_HOST);
            $label = is_string($host) && $host !== '' ? $host : $url;
        }

        $notes = $this->normalizeInlineText($notes);
        $knowledgeText = $this->normalizeInlineText(implode("\n", array_filter([$label, $notes])));

        $meta = [
            'source' => 'brand_center',
            'ingestion_scope' => 'tenant_library',
            'content_origin' => 'reference_link',
            'source_url' => $url,
            'source_label' => $label,
            'knowledge_text' => $this->buildKnowledgeExcerpt($knowledgeText !== '' ? $knowledgeText : $label, 1600),
            'text_excerpt' => $this->buildKnowledgeExcerpt(trim(implode(' | ', array_filter([$label, $notes]))), 280),
        ];

        if ($notes !== '') {
            $meta['grounding_notes'] = $notes;
        }

        return BrandAsset::query()->create([
            'tenant_id' => $tenantId,
            'content_plan_id' => null,
            'kind' => 'link',
            'path' => '',
            'original_name' => Str::limit($label, 180, ''),
            'size' => strlen($url . "\n" . $knowledgeText),
            'mime' => 'text/uri-list',
            'meta' => $meta,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocumentAssetMeta(UploadedFile $file): array
    {
        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));
        $mime = strtolower(trim((string) ($file->getMimeType() ?? '')));
        $extractedText = $this->extractDocumentKnowledgeText($file, $extension, $mime);

        $meta = [
            'content_origin' => 'uploaded_document',
            'document_extension' => $extension,
            'document_mime' => $mime,
            'text_extract_status' => $extractedText !== '' ? 'available' : 'metadata_only',
        ];

        if ($extractedText !== '') {
            $meta['knowledge_text'] = $this->buildKnowledgeExcerpt($extractedText, 2400);
            $meta['text_excerpt'] = $this->buildKnowledgeExcerpt($extractedText, 420);
        }

        return $meta;
    }

    private function extractDocumentKnowledgeText(UploadedFile $file, string $extension, string $mime): string
    {
        $textLikeExtensions = ['txt', 'md', 'csv', 'json', 'xml', 'html', 'htm'];
        $textLikeMimes = [
            'application/json',
            'application/xml',
            'application/xhtml+xml',
            'text/plain',
            'text/csv',
            'text/html',
            'text/markdown',
            'text/xml',
        ];

        if (!in_array($extension, $textLikeExtensions, true) && !in_array($mime, $textLikeMimes, true) && !str_starts_with($mime, 'text/')) {
            return '';
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return '';
        }

        $raw = @file_get_contents($path, false, null, 0, 512000);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        if (in_array($extension, ['html', 'htm'], true) || $mime === 'text/html' || $mime === 'application/xhtml+xml') {
            $raw = strip_tags($raw);
        }

        return $this->normalizeInlineText($raw);
    }

    private function buildKnowledgeExcerpt(string $text, int $limit): string
    {
        return Str::limit($this->normalizeInlineText($text), $limit, '');
    }

    private function normalizeInlineText(string $text): string
    {
        $text = $this->normalizeUtf8($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\r\n?/', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function sanitizeAssetForDisplay(BrandAsset $asset): BrandAsset
    {
        $asset->original_name = $this->normalizeUtf8((string) ($asset->original_name ?? ''));
        $asset->path = trim((string) ($asset->path ?? ''));
        $asset->mime = $this->normalizeUtf8((string) ($asset->mime ?? ''));
        $asset->meta = is_array($asset->meta) ? $this->sanitizeRecursiveStrings($asset->meta) : [];

        return $asset;
    }

    private function sanitizeRecursiveStrings(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeRecursiveStrings($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->normalizeUtf8($value);
        }

        return $value;
    }

    private function normalizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($sanitized) && $sanitized !== '') {
            $text = $sanitized;
        }

        return trim($text);
    }

    public function storeGuidedPersonaVariable(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'required|string|max:1000',
            'persona_role' => 'nullable|string|max:160',
            'immutable_traits' => 'required|string|max:1000',
            'look_notes' => 'nullable|string|max:1000',
            'styling_notes' => 'nullable|string|max:1000',
            'prompt_notes' => 'nullable|string|max:1200',
            'usage_notes' => 'nullable|string|max:1200',
            'shot_front' => 'required|file|mimes:png,jpg,jpeg,webp|max:6144',
            'shot_three_quarter_left' => 'required|file|mimes:png,jpg,jpeg,webp|max:6144',
            'shot_three_quarter_right' => 'required|file|mimes:png,jpg,jpeg,webp|max:6144',
            'shot_profile' => 'required|file|mimes:png,jpg,jpeg,webp|max:6144',
            'shot_half_body' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:6144',
            'reference_video' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/webm|max:51200',
            'voice_sample' => 'nullable|file|mimes:mp3,wav,m4a,ogg,webm|max:15360',
        ]);

        try {
            $result = $this->guidedAssetVariableService->createPersonaPack($request->user(), $data);
        } catch (\Throwable $e) {
            return redirect()
                ->route('profile.brand')
                ->withInput()
                ->withErrors(['guided_persona' => $e->getMessage()]);
        }

        $assetCount = count($result['assets'] ?? []);
        $hasVoiceSample = !empty($data['voice_sample']);
        $statusDetail = $hasVoiceSample
            ? 'riferimenti pronti per immagini, video e voce.'
            : 'riferimenti pronti per immagini e video.';

        return redirect()
            ->route('profile.brand')
            ->with('status', "Persona pack creato: {$result['variable']->name} con {$assetCount} {$statusDetail}");
    }
    public function destroyVariable(Request $request, AssetVariable $assetVariable)
    {
        $user = $request->user();

        if ((int) $assetVariable->tenant_id !== (int) $user->tenant_id) {
            abort(403);
        }

        $assetVariable->delete();

        return redirect()->route('profile.brand')->with('status', 'Variabile asset eliminata.');
    }

    public function storeVariableAssets(Request $request, AssetVariable $assetVariable)
    {
        $user = $request->user();
        if ((int) $assetVariable->tenant_id !== (int) $user->tenant_id) {
            abort(403);
        }

        $data = $request->validate([
            'variable_images' => 'nullable|array|max:12',
            'variable_images.*' => 'file|mimes:png,jpg,jpeg,webp|max:6144',
            'variable_videos' => 'nullable|array|max:6',
            'variable_videos.*' => 'file|mimetypes:video/mp4,video/quicktime,video/webm|max:51200',
            'variable_audios' => 'nullable|array|max:6',
            'variable_audios.*' => 'file|mimes:mp3,wav,m4a,ogg,webm|max:20480',
            'variable_asset_notes' => 'nullable|string|max:1200',
            'variable_asset_set_video_reference' => 'nullable|boolean',
            'variable_asset_set_voice_reference' => 'nullable|boolean',
        ], [], [
            'variable_images' => 'immagini aggiuntive',
            'variable_images.*' => 'immagine aggiuntiva',
            'variable_videos' => 'video aggiuntivi',
            'variable_videos.*' => 'video aggiuntivo',
            'variable_audios' => 'audio aggiuntivi',
            'variable_audios.*' => 'audio aggiuntivo',
        ]);

        $imageFiles = array_values(array_filter((array) $request->file('variable_images', []), fn ($file) => $file instanceof UploadedFile));
        $videoFiles = array_values(array_filter((array) $request->file('variable_videos', []), fn ($file) => $file instanceof UploadedFile));
        $audioFiles = array_values(array_filter((array) $request->file('variable_audios', []), fn ($file) => $file instanceof UploadedFile));

        if (empty($imageFiles) && empty($videoFiles) && empty($audioFiles)) {
            return redirect()
                ->route('profile.brand')
                ->with('status', 'Carica almeno un immagine, un video o un audio per arricchire la variabile.');
        }

        $notes = $this->normalizeInlineText((string) ($data['variable_asset_notes'] ?? ''));
        $setVideoReference = (bool) ($data['variable_asset_set_video_reference'] ?? false);
        $setVoiceReference = (bool) ($data['variable_asset_set_voice_reference'] ?? false);

        $tenantId = (int) $user->tenant_id;
        $baseDir = 'brand-assets/' . $tenantId . '/variables/' . ($assetVariable->slug ?: ('var-' . $assetVariable->id));

        DB::transaction(function () use (
            $assetVariable,
            $tenantId,
            $baseDir,
            $notes,
            $imageFiles,
            $videoFiles,
            $audioFiles,
            $setVideoReference,
            $setVoiceReference
        ): void {
            $newImageAssets = [];
            $newVideoAssets = [];
            $newAudioAssets = [];
            $newAssetIds = [];

            foreach ($imageFiles as $file) {
                $asset = $this->storeBrandAssetUpload(
                    tenantId: $tenantId,
                    file: $file,
                    kind: 'image',
                    directory: $baseDir . '/images',
                    notes: $notes,
                    meta: $this->buildVariableAssetUploadMeta($assetVariable, 'image')
                );

                if ($asset) {
                    $newImageAssets[] = $asset;
                    $newAssetIds[] = (int) $asset->id;
                }
            }

            foreach ($videoFiles as $file) {
                $asset = $this->storeBrandAssetUpload(
                    tenantId: $tenantId,
                    file: $file,
                    kind: 'video',
                    directory: $baseDir . '/videos',
                    notes: $notes,
                    meta: $this->buildVariableAssetUploadMeta($assetVariable, 'video')
                );

                if ($asset) {
                    $newVideoAssets[] = $asset;
                    $newAssetIds[] = (int) $asset->id;
                }
            }

            foreach ($audioFiles as $file) {
                $asset = $this->storeBrandAssetUpload(
                    tenantId: $tenantId,
                    file: $file,
                    kind: 'audio',
                    directory: $baseDir . '/audio',
                    notes: $notes,
                    meta: $this->buildVariableAssetUploadMeta($assetVariable, 'audio')
                );

                if ($asset) {
                    $newAudioAssets[] = $asset;
                    $newAssetIds[] = (int) $asset->id;
                }
            }

            $existingAssetIds = collect((array) ($assetVariable->asset_ids ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->values()
                ->all();

            $assetVariable->asset_ids = array_values(array_unique(array_merge($existingAssetIds, $newAssetIds)));

            if ((int) ($assetVariable->canonical_asset_id ?? 0) < 1 && !empty($newImageAssets)) {
                $assetVariable->canonical_asset_id = (int) $newImageAssets[0]->id;
            }

            $profile = is_array($assetVariable->profile) ? $assetVariable->profile : [];
            if (!empty($newImageAssets)) {
                $profile = $this->appendVariableImageReferences($profile, $newImageAssets);
            }

            if (!empty($newVideoAssets)) {
                $shouldPromoteVideo = $setVideoReference || trim((string) data_get($profile, 'reference_video_path', '')) === '';
                if ($shouldPromoteVideo) {
                    $primaryVideo = $newVideoAssets[0];
                    data_set($profile, 'reference_video_asset_id', (int) $primaryVideo->id);
                    data_set($profile, 'reference_video_path', (string) $primaryVideo->path);
                }
            }

            if (!empty($newAudioAssets)) {
                $shouldPromoteVoice = $setVoiceReference || (int) ($assetVariable->voice_asset_id ?? 0) < 1;
                if ($shouldPromoteVoice) {
                    $primaryAudio = $newAudioAssets[0];
                    $voiceProvider = $this->defaultVoiceCloneProvider();

                    $assetVariable->voice_asset_id = (int) $primaryAudio->id;
                    $assetVariable->voice_provider = $voiceProvider;
                    $assetVariable->voice_provider_voice_id = null;
                    $assetVariable->voice_status = 'sample_ready';

                    data_set($profile, 'reference_voice_asset_id', (int) $primaryAudio->id);
                    data_set($profile, 'reference_voice_path', (string) $primaryAudio->path);
                    data_set($profile, 'voice_reference.label', 'Campione voce reale');
                    data_set($profile, 'voice_reference.sample_asset_id', (int) $primaryAudio->id);
                    data_set($profile, 'voice_reference.sample_path', (string) $primaryAudio->path);
                    data_set($profile, 'voice_reference.sample_name', (string) ($primaryAudio->original_name ?? ''));
                    data_set($profile, 'voice_reference.provider', $voiceProvider);
                    data_set($profile, 'voice_reference.provider_voice_id', null);
                    data_set($profile, 'voice_reference.status', 'sample_ready');
                }
            }

            if ($notes !== '') {
                $profile = $this->appendProfileTextValue(
                    $profile,
                    'prompt_notes',
                    'Nuovi riferimenti: ' . $notes,
                    2000
                );
                $profile = $this->appendProfileTextValue($profile, 'usage_notes', $notes, 2000);
            }

            if ((int) data_get($profile, 'canonical_asset_id', 0) < 1 && (int) ($assetVariable->canonical_asset_id ?? 0) > 0) {
                data_set($profile, 'canonical_asset_id', (int) $assetVariable->canonical_asset_id);
                data_set($profile, 'preferred_still_asset_id', (int) $assetVariable->canonical_asset_id);
            }

            $assetVariable->profile = $profile;
            $linkedAssets = BrandAsset::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('content_plan_id')
                ->whereIn('id', (array) $assetVariable->asset_ids)
                ->get();
            $assetVariable->identity_pack = $this->assetIdentityService->synthesizeIdentityPackForVariable($assetVariable, $linkedAssets);
            $assetVariable->save();

            $extraAssetIds = [];
            if ((int) ($assetVariable->voice_asset_id ?? 0) > 0) {
                $extraAssetIds[] = (int) $assetVariable->voice_asset_id;
            }

            $this->assetIdentityService->syncAssetMetaForVariable($assetVariable, (array) $assetVariable->asset_ids, $extraAssetIds);
        });

        $uploadedCount = count($imageFiles) + count($videoFiles) + count($audioFiles);

        return redirect()
            ->route('profile.brand')
            ->with('status', "Variabile {$assetVariable->name} aggiornata con {$uploadedCount} nuovi riferimenti.");
    }

    /**
     * Remove deleted brand assets from variable references so runtime selection stays valid.
     *
     * @param  array<int, int|string>  $assetIds
     */
    private function detachAssetsFromVariables(int $tenantId, array $assetIds): void
    {
        $deletedIds = collect($assetIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($deletedIds->isEmpty()) {
            return;
        }

        $deletedLookup = $deletedIds->flip()->all();

        AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->each(function (AssetVariable $variable) use ($deletedLookup, $tenantId): void {
                $assetIds = collect((array) ($variable->asset_ids ?? []))
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => $id > 0)
                    ->unique()
                    ->values();

                $remainingAssetIds = $assetIds
                    ->reject(fn (int $id) => array_key_exists($id, $deletedLookup))
                    ->values()
                    ->all();

                $canonicalAssetId = $variable->canonical_asset_id !== null ? (int) $variable->canonical_asset_id : null;
                if ($canonicalAssetId !== null && array_key_exists($canonicalAssetId, $deletedLookup)) {
                    $canonicalAssetId = null;
                }
                if ($canonicalAssetId !== null && !in_array($canonicalAssetId, $remainingAssetIds, true)) {
                    $canonicalAssetId = null;
                }
                if ($canonicalAssetId === null) {
                    $canonicalAssetId = !empty($remainingAssetIds) ? (int) $remainingAssetIds[0] : null;
                }

                $profile = is_array($variable->profile) ? $variable->profile : [];
                $updated = $remainingAssetIds !== $assetIds->all() || $canonicalAssetId !== ($variable->canonical_asset_id !== null ? (int) $variable->canonical_asset_id : null);

                if (is_array(data_get($profile, 'shot_summary', null))) {
                    $shotSummary = collect((array) data_get($profile, 'shot_summary', []))
                        ->filter(function ($shot) use ($deletedLookup): bool {
                            if (!is_array($shot)) {
                                return false;
                            }

                            $assetId = (int) ($shot['asset_id'] ?? 0);

                            return $assetId < 1 || !array_key_exists($assetId, $deletedLookup);
                        })
                        ->values()
                        ->all();
                    data_set($profile, 'shot_summary', $shotSummary);
                }

                if ($canonicalAssetId !== null) {
                    data_set($profile, 'canonical_asset_id', $canonicalAssetId);
                    data_set($profile, 'preferred_still_asset_id', $canonicalAssetId);
                } else {
                    data_set($profile, 'canonical_asset_id', null);
                    data_set($profile, 'preferred_still_asset_id', null);
                }

                $referenceVideoAssetId = (int) data_get($profile, 'reference_video_asset_id', 0);
                if ($referenceVideoAssetId > 0 && array_key_exists($referenceVideoAssetId, $deletedLookup)) {
                    data_set($profile, 'reference_video_asset_id', null);
                    data_set($profile, 'reference_video_path', null);
                    $updated = true;
                }

                $voiceAssetId = (int) ($variable->voice_asset_id ?? 0);
                if ($voiceAssetId > 0 && array_key_exists($voiceAssetId, $deletedLookup)) {
                    $variable->voice_asset_id = null;
                    $variable->voice_provider_voice_id = null;
                    $variable->voice_status = 'sample_missing';
                    data_set($profile, 'reference_voice_asset_id', null);
                    data_set($profile, 'reference_voice_path', null);
                    data_set($profile, 'voice_reference.sample_asset_id', null);
                    data_set($profile, 'voice_reference.sample_path', null);
                    data_set($profile, 'voice_reference.sample_name', null);
                    data_set($profile, 'voice_reference.provider_voice_id', null);
                    data_set($profile, 'voice_reference.status', 'sample_missing');
                    $updated = true;
                }

                if (!$updated && $profile === $variable->profile) {
                    return;
                }

                $variable->asset_ids = $remainingAssetIds;
                $variable->canonical_asset_id = $canonicalAssetId;
                $variable->profile = $profile;
                $remainingAssets = BrandAsset::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('content_plan_id')
                    ->whereIn('id', $remainingAssetIds)
                    ->get();
                $variable->identity_pack = $this->assetIdentityService->synthesizeIdentityPackForVariable($variable, $remainingAssets);
                $variable->save();
            });
    }

    private function extractStudioPayload(array $data, TenantProfile $profile, EditorialStrategy $strategy): array
    {
        $analysisCurrent = (array) ($strategy->analysis_framework ?? []);
        $visualCurrent = (array) ($strategy->visual_system ?? []);
        $publishingCurrent = (array) ($strategy->publishing_system ?? []);

        return [
            'analysis_framework' => [
                'primary_goal' => trim((string) ($data['strategy_goal_primary'] ?? data_get($analysisCurrent, 'primary_goal', $profile->default_goal ?? 'Awareness + Lead'))),
                'secondary_goal' => trim((string) ($data['strategy_goal_secondary'] ?? data_get($analysisCurrent, 'secondary_goal', 'Engagement + Fiducia'))),
                'kpi_primary' => trim((string) ($data['strategy_kpi_primary'] ?? data_get($analysisCurrent, 'kpi_primary', 'Copertura qualificata'))),
                'kpi_secondary' => trim((string) ($data['strategy_kpi_secondary'] ?? data_get($analysisCurrent, 'kpi_secondary', 'Interazioni utili e conversione contatti'))),
                'audience_focus' => trim((string) ($data['strategy_audience_focus'] ?? data_get($analysisCurrent, 'audience_focus', $profile->target ?? ''))),
                'offer_focus' => trim((string) ($data['strategy_offer_focus'] ?? data_get($analysisCurrent, 'offer_focus', $profile->seasonal_offers ?? ''))),
                'asset_readiness' => data_get($analysisCurrent, 'asset_readiness', []),
            ],
            'visual_system' => [
                'style' => trim((string) ($data['strategy_visual_style'] ?? data_get($visualCurrent, 'style', 'Pulito, moderno, realistico, orientato conversione'))),
                'mood' => trim((string) ($data['strategy_visual_mood'] ?? data_get($visualCurrent, 'mood', 'Professionale con energia positiva'))),
                'palette' => $this->parsePalette((string) ($data['strategy_visual_palette'] ?? implode(', ', (array) data_get($visualCurrent, 'palette', [])))),
                'palette_mode' => trim((string) ($data['strategy_palette_mode'] ?? data_get($visualCurrent, 'palette_mode', 'brand_palette'))),
                'logo_rule' => trim((string) ($data['strategy_logo_rule'] ?? data_get($visualCurrent, 'logo_rule', 'Usa solo loghi reali caricati in assets.'))),
                'visual_do' => trim((string) ($data['strategy_visual_do'] ?? data_get($visualCurrent, 'visual_do', 'Composizioni leggibili e coerenti.'))),
                'visual_dont' => trim((string) ($data['strategy_visual_dont'] ?? data_get($visualCurrent, 'visual_dont', 'No watermark e no testo inventato.'))),
            ],
            'publishing_system' => [
                'posts_per_week' => (int) ($data['strategy_posts_per_week'] ?? data_get($publishingCurrent, 'posts_per_week', $profile->default_posts_per_week ?? 5)),
                'best_days' => trim((string) ($data['strategy_best_days'] ?? data_get($publishingCurrent, 'best_days', 'Lun-Mar-Gio'))),
                'best_times' => $this->parseTimes((string) ($data['strategy_best_times'] ?? implode(', ', (array) data_get($publishingCurrent, 'best_times', ['11:00', '15:00', '19:00'])))),
                'channel_priority' => trim((string) ($data['strategy_channel_priority'] ?? data_get($publishingCurrent, 'channel_priority', implode(', ', (array) ($profile->default_platforms ?? ['instagram']))))),
                'format_priority' => trim((string) ($data['strategy_format_priority'] ?? data_get($publishingCurrent, 'format_priority', implode(', ', (array) ($profile->default_formats ?? ['post']))))),
                'cadence_rule' => trim((string) ($data['strategy_cadence_rule'] ?? data_get($publishingCurrent, 'cadence_rule', 'Alterna rubriche e formati evitando ripetizioni.'))),
            ],
            'strategy_notes' => trim((string) ($data['strategy_notes'] ?? ($strategy->strategy_notes ?? ''))),
            'is_locked' => (bool) ($data['strategy_locked'] ?? ($strategy->is_locked ?? false)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parsePalette(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $p = strtoupper(trim($part));
            if ($p === '') {
                continue;
            }
            if (!str_starts_with($p, '#')) {
                $p = '#' . $p;
            }
            if (preg_match('/^#[0-9A-F]{6}$/', $p) === 1) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return array<int, string>
     */
    private function parseTimes(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $p = trim($part);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^\d{1,2}:\d{2}$/', $p) !== 1) {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $p, 2));
            if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
                continue;
            }
            $out[] = sprintf('%02d:%02d', $h, $m);
        }

        return !empty($out) ? array_values(array_unique($out)) : ['11:00', '15:00', '19:00'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVariableAssetUploadMeta(AssetVariable $variable, string $kind): array
    {
        $kind = strtolower(trim($kind));

        return [
            'source' => 'brand_center_variable_extension',
            'ingestion_scope' => 'asset_variable_library',
            'variable_name' => (string) ($variable->name ?? ''),
            'variable_kind' => (string) ($variable->kind ?? 'custom'),
            'identity_kind' => (string) ($variable->kind ?? 'custom'),
            'slot' => match ($kind) {
                'video' => 'reference_video',
                'audio' => 'voice_sample',
                default => 'supporting_reference',
            },
            'slot_label' => match ($kind) {
                'video' => 'Video riferimento aggiuntivo',
                'audio' => 'Campione voce aggiuntivo',
                default => 'Riferimento immagine aggiuntivo',
            },
            'training_priority' => 'supporting',
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, BrandAsset>  $assets
     * @return array<string, mixed>
     */
    private function appendVariableImageReferences(array $profile, array $assets): array
    {
        $shotSummary = collect((array) data_get($profile, 'shot_summary', []))
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();

        $nextIndex = count($shotSummary) + 1;
        foreach ($assets as $asset) {
            $shotSummary[] = [
                'slot' => 'reference_still_' . $nextIndex,
                'label' => 'Riferimento extra ' . $nextIndex,
                'asset_id' => (int) $asset->id,
                'path' => (string) $asset->path,
            ];
            $nextIndex++;
        }

        data_set($profile, 'shot_summary', $shotSummary);
        data_set($profile, 'shot_count', count($shotSummary));

        return $profile;
    }

    private function defaultVoiceCloneProvider(): ?string
    {
        $provider = strtolower(trim((string) config('generation.voice_clone_provider_default', 'elevenlabs')));
        if ($provider === '') {
            return null;
        }

        $provider = SpeechProviderResolver::normalize($provider);

        return $provider === 'elevenlabs' ? $provider : null;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function appendProfileTextValue(array $profile, string $key, string $value, int $limit = 2000): array
    {
        $value = $this->normalizeInlineText($value);
        if ($value === '') {
            return $profile;
        }

        $existing = trim((string) data_get($profile, $key, ''));
        if ($existing === '') {
            data_set($profile, $key, Str::limit($value, $limit, ''));

            return $profile;
        }

        if (str_contains($existing, $value)) {
            return $profile;
        }

        data_set($profile, $key, Str::limit($existing . "\n" . $value, $limit, ''));

        return $profile;
    }

    public function updateAssetDescription(Request $request, BrandAsset $asset)
    {
        $user = $request->user();

        if ((int) $asset->tenant_id !== (int) $user->tenant_id) {
            abort(403);
        }

        $data = $request->validate([
            'ai_description' => 'nullable|string|max:500',
        ]);

        $asset->update([
            'ai_description' => $this->normalizeInlineText((string) ($data['ai_description'] ?? '')),
        ]);

        return response()->json(['ok' => true]);
    }
}



