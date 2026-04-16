<?php

namespace App\Services\Onboarding;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AssetVariableService;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\ContentHistoryAnalyzer;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\MemoryBuilderService;
use App\Support\GenerationExecution;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QuickstartOnboardingService
{
    public function __construct(
        private readonly EditorialStrategyService $editorialStrategyService,
        private readonly EditorialPlanBuilder $editorialPlanBuilder,
        private readonly ContentHistoryAnalyzer $historyAnalyzer,
        private readonly ContentGenerator $contentGenerator,
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly AssetVariableService $assetVariableService
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     * @return array{profile: TenantProfile, plan: ContentPlan, created_items: array<int, ContentItem>}
     */
    public function runQuickstart(
        User $user,
        array $data,
        ?UploadedFile $logo = null,
        array $images = []
    ): array {
        $tenantId = (int) $user->tenant_id;

        $existingProfile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $existingImageCount = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'image')
            ->count();

        if ($existingImageCount < 1 && count($images) < 1) {
            throw new \RuntimeException('Per creare la prova iniziale serve almeno 1 immagine reale di riferimento.');
        }

        $result = DB::transaction(function () use ($user, $data, $logo, $images, $existingProfile, $tenantId) {
            $profile = TenantProfile::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                $this->profilePayload($data, $existingProfile)
            );

            $storedAssetIds = $this->storeAssets(
                tenantId: $tenantId,
                logo: $logo,
                images: $images
            );

            $this->storeQuickVariable(
                tenantId: $tenantId,
                data: $data,
                preferredAssetIds: $storedAssetIds
            );

            $this->deleteExistingQuickstartDemo($tenantId, $profile);

            $profile = $profile->fresh() ?? $profile;

            $profileData = $this->buildProfileData($profile);
            $assets = $this->loadBrandAssets($tenantId);
            $assetVariables = $this->assetVariableService->catalogForTenant($tenantId);
            $strategyModel = $this->editorialStrategyService->refreshForTenant($tenantId, $profile);
            $strategy = $this->editorialStrategyService->toRuntimeContext(
                $strategyModel,
                $this->buildBrandReferences($profileData, $assets, $assetVariables)
            );
            $history = $this->historyAnalyzer->snapshot(
                $tenantId,
                (int) config('editorial.history_limit', 120)
            );
            $memory = $this->memoryBuilder->buildForTenant($tenantId, 40);

            [$start, $end] = $this->demoDateRange();
            $plan = ContentPlan::query()->create([
                'tenant_id' => $tenantId,
                'created_by' => (int) $user->id,
                'name' => 'Demo rapida - ' . ($profile->business_name ?: 'Brand'),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => 'draft',
                'settings' => [
                    'goal' => $profile->default_goal ?: $this->defaultGoal($profile),
                    'tone' => $profile->default_tone ?: 'professionale',
                    'posts_total' => 3,
                    'platforms' => ['instagram', 'facebook'],
                    'formats' => ['post', 'post', 'reel'],
                    'mode' => 'onboarding_quickstart_demo',
                    'demo_label' => 'Prova iniziale rigenerabile',
                    'demo_note' => 'Questo piano serve a mostrare il potenziale dell app e puo essere eliminato o rigenerato.',
                    'generated_from' => 'brand_quickstart',
                    'image_provider' => 'nanobanana',
                    'video_provider' => 'google_veo',
                ],
                'strategy' => $strategy,
            ]);

            $planItems = $this->editorialPlanBuilder->buildPlan(
                tenantId: $tenantId,
                strategy: $strategy,
                history: $history,
                period: [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'total_posts' => 3,
                ],
                options: [
                    'platforms' => ['instagram', 'facebook'],
                    'formats' => ['post', 'post', 'reel'],
                    'memory' => $memory,
                ]
            );

            $createdItems = $this->contentGenerator->generateForPlan($plan, $planItems, [
                'user_id' => (int) $user->id,
                'profile_data' => $profileData,
                'strategy' => $strategy,
                'memory' => $memory,
                'assets' => $assets,
                'asset_variables' => $assetVariables,
            ]);

            foreach ($createdItems as $index => $item) {
                $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
                $meta['source'] = 'onboarding_quickstart_demo';
                $meta['demo'] = [
                    'is_quickstart' => true,
                    'plan_id' => (int) $plan->id,
                    'item_index' => $index + 1,
                    'regenerable' => true,
                    'deletable' => true,
                ];

                // Forza provider per ogni item: reel → Veo 3.1, post → NanoBanana.
                // video_no_fallback: true impedisce il silent downgrade a OpenAI se Veo fallisce.
                $format = (string) ($item->format ?? 'post');
                if ($format === 'reel' || $format === 'video') {
                    $meta['video_provider'] = 'google_veo';
                    $meta['video_no_fallback'] = true;
                } else {
                    $meta['image_provider'] = 'nanobanana';
                }

                $item->ai_meta = $meta;
                $item->save();
            }

            $profile->forceFill([
                'quickstart_generated_at' => now(),
                'quickstart_last_plan_id' => (int) $plan->id,
            ])->save();

            return [
                'profile' => $profile->fresh() ?? $profile,
                'plan' => $plan->fresh() ?? $plan,
                'created_items' => $createdItems,
            ];
        });

        $this->dispatchGenerationJobs($result['created_items']);

        return $result;
    }

    /**
     * @return array{profile: TenantProfile, plan: ContentPlan, created_items: array<int, ContentItem>}
     */
    public function regenerateQuickstart(User $user): array
    {
        $tenantId = (int) $user->tenant_id;
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();

        if (!$profile) {
            throw new \RuntimeException('Completa prima il quickstart brand.');
        }

        $hasImages = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'image')
            ->exists();

        if (!$hasImages) {
            throw new \RuntimeException('Per rigenerare la demo serve almeno 1 immagine nel Brand Center.');
        }

        return $this->runQuickstart($user, $profile->toArray());
    }

    public function deleteQuickstartDemo(User $user): int
    {
        $tenantId = (int) $user->tenant_id;
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        if (!$profile) {
            return 0;
        }

        $deletedPlans = DB::transaction(function () use ($tenantId, $profile) {
            $deletedPlans = 0;
            $planIds = $this->resolveQuickstartPlanIds($tenantId, $profile);

            if (!empty($planIds)) {
                ContentItem::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('content_plan_id', $planIds)
                    ->delete();

                $deletedPlans = ContentPlan::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $planIds)
                    ->delete();
            }

            $profile->forceFill([
                'quickstart_last_plan_id' => null,
                'quickstart_generated_at' => null,
                'quickstart_dismissed_at' => now(),
            ])->save();

            return (int) $deletedPlans;
        });

        return $deletedPlans;
    }

    public function saveQuickstartDemo(User $user): int
    {
        $tenantId = (int) $user->tenant_id;
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();

        if (!$profile) {
            return 0;
        }

        return DB::transaction(function () use ($tenantId, $profile) {
            $planIds = $this->resolveQuickstartPlanIds($tenantId, $profile);
            $savedAt = now();

            if (!empty($planIds)) {
                $plans = ContentPlan::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $planIds)
                    ->get();

                foreach ($plans as $plan) {
                    $settings = is_array($plan->settings) ? $plan->settings : [];
                    $settings['mode'] = 'quickstart_saved';
                    $settings['saved_from'] = 'onboarding_quickstart_demo';
                    $settings['quickstart_saved_at'] = $savedAt->toIso8601String();
                    unset($settings['demo_label'], $settings['demo_note']);

                    $plan->forceFill([
                        'name' => $this->savedPlanName($plan->name),
                        'settings' => $settings,
                    ])->save();
                }

                $items = ContentItem::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('content_plan_id', $planIds)
                    ->get();

                foreach ($items as $item) {
                    $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
                    unset($meta['demo']);
                    $meta['source'] = 'quickstart_saved_plan';
                    $meta['quickstart'] = [
                        'saved' => true,
                        'saved_at' => $savedAt->toIso8601String(),
                    ];
                    $item->ai_meta = $meta;
                    $item->save();
                }
            }

            $profile->forceFill([
                'quickstart_last_plan_id' => null,
                'quickstart_dismissed_at' => $savedAt,
            ])->save();

            return count($planIds);
        });
    }

    public function dismissQuickstart(User $user): bool
    {
        $tenantId = (int) $user->tenant_id;
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();

        if (!$profile) {
            return false;
        }

        $profile->forceFill([
            'quickstart_dismissed_at' => now(),
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profilePayload(array $data, ?TenantProfile $existingProfile): array
    {
        $industry = trim((string) ($data['industry'] ?? $existingProfile?->industry ?? ''));
        $services = trim((string) ($data['services'] ?? $existingProfile?->services ?? ''));
        $target = trim((string) ($data['target'] ?? $existingProfile?->target ?? ''));
        $cta = trim((string) ($data['cta'] ?? $existingProfile?->cta ?? ''));
        $notes = trim((string) ($data['notes'] ?? $existingProfile?->notes ?? ''));
        $website = trim((string) ($data['website'] ?? $existingProfile?->website ?? ''));
        $tone = trim((string) ($data['default_tone'] ?? $existingProfile?->default_tone ?? 'professionale'));
        $startedAt = $existingProfile?->onboarding_started_at ?: now();

        return [
            'business_name' => trim((string) ($data['business_name'] ?? $existingProfile?->business_name ?? '')),
            'industry' => $industry !== '' ? $industry : null,
            'website' => $website !== '' ? $website : null,
            'notes' => $notes !== '' ? $notes : null,
            'services' => $services !== '' ? $services : null,
            'target' => $target !== '' ? $target : null,
            'cta' => $cta !== '' ? $cta : null,
            'vision' => $existingProfile?->vision,
            'values' => $existingProfile?->values,
            'business_hours' => $existingProfile?->business_hours,
            'seasonal_offers' => $existingProfile?->seasonal_offers,
            'brand_palette' => $existingProfile?->brand_palette,
            'default_goal' => trim((string) ($data['default_goal'] ?? $existingProfile?->default_goal ?? $this->defaultGoalFromInput($industry, $services))),
            'default_tone' => $tone !== '' ? $tone : 'professionale',
            'default_posts_per_week' => 3,
            'default_platforms' => ['instagram', 'facebook'],
            'default_formats' => ['post', 'reel'],
            'completed_at' => now(),
            'onboarding_started_at' => $startedAt,
            'onboarding_completed_at' => now(),
            'quickstart_dismissed_at' => null,
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return array<int, int>
     */
    private function storeAssets(int $tenantId, ?UploadedFile $logo = null, array $images = []): array
    {
        $storedAssetIds = [];
        $baseDir = 'brand-assets/' . $tenantId;

        if ($logo instanceof UploadedFile) {
            $path = $logo->store($baseDir . '/logo', 'public');

            $asset = BrandAsset::query()->create([
                'tenant_id' => $tenantId,
                'content_plan_id' => null,
                'kind' => 'logo',
                'path' => $path,
                'original_name' => $logo->getClientOriginalName(),
                'size' => $logo->getSize(),
                'mime' => $logo->getMimeType(),
            ]);

            $storedAssetIds[] = (int) $asset->id;
        }

        foreach ($images as $image) {
            if (!$image instanceof UploadedFile) {
                continue;
            }

            $path = $image->store($baseDir . '/images', 'public');

            $asset = BrandAsset::query()->create([
                'tenant_id' => $tenantId,
                'content_plan_id' => null,
                'kind' => 'image',
                'path' => $path,
                'original_name' => $image->getClientOriginalName(),
                'size' => $image->getSize(),
                'mime' => $image->getMimeType(),
            ]);

            $storedAssetIds[] = (int) $asset->id;
        }

        return array_values(array_unique(array_filter($storedAssetIds, fn ($id) => (int) $id > 0)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $preferredAssetIds
     */
    private function storeQuickVariable(int $tenantId, array $data, array $preferredAssetIds = []): void
    {
        $name = trim((string) ($data['quickstart_variable_name'] ?? ''));
        if ($name === '') {
            return;
        }

        $assetIds = !empty($preferredAssetIds)
            ? $this->assetVariableService->sanitizeAssetIdsForTenant($tenantId, $preferredAssetIds)
            : BrandAsset::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('content_plan_id')
                ->whereIn('kind', ['image', 'logo'])
                ->latest('id')
                ->limit(8)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if (empty($assetIds)) {
            return;
        }

        AssetVariable::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => $this->assetVariableService->buildUniqueSlugForTenant($tenantId, $name),
            'kind' => (string) ($data['quickstart_variable_kind'] ?? 'custom'),
            'description' => trim((string) ($data['quickstart_variable_description'] ?? '')),
            'asset_ids' => $assetIds,
            'is_active' => true,
        ]);
    }

    private function deleteExistingQuickstartDemo(int $tenantId, TenantProfile $profile): void
    {
        $planIds = $this->resolveQuickstartPlanIds($tenantId, $profile);
        if (empty($planIds)) {
            return;
        }

        ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('content_plan_id', $planIds)
            ->delete();

        ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $planIds)
            ->delete();
    }

    /**
     * @return array<int, int>
     */
    private function resolveQuickstartPlanIds(int $tenantId, TenantProfile $profile): array
    {
        $planIds = [];

        if ((int) ($profile->quickstart_last_plan_id ?? 0) > 0) {
            $planIds[] = (int) $profile->quickstart_last_plan_id;
        }

        $extraPlanIds = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->get(['id', 'settings'])
            ->filter(fn (ContentPlan $plan) => data_get($plan->settings, 'mode') === 'onboarding_quickstart_demo')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($planIds, $extraPlanIds)));
    }

    private function savedPlanName(?string $name): string
    {
        $current = trim((string) $name);
        if ($current === '') {
            return 'Piano iniziale salvato';
        }

        if (str_starts_with($current, 'Demo rapida - ')) {
            return 'Piano iniziale - ' . substr($current, strlen('Demo rapida - '));
        }

        return $current;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function demoDateRange(): array
    {
        $tz = config('app.timezone', 'Europe/Rome');
        $start = now($tz)->addDay()->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        return [$start, $end];
    }

    private function defaultGoal(TenantProfile $profile): string
    {
        return $profile->default_goal ?: $this->defaultGoalFromInput(
            (string) ($profile->industry ?? ''),
            (string) ($profile->services ?? '')
        );
    }

    private function defaultGoalFromInput(string $industry, string $services): string
    {
        $industry = trim($industry);
        $services = trim($services);

        if ($industry !== '') {
            return "Far capire il valore di {$industry} e portare prime richieste di contatto.";
        }

        if ($services !== '') {
            return 'Mostrare il valore dei servizi e generare interesse reale.';
        }

        return 'Far capire il valore del brand e portare prime richieste di contatto.';
    }

    private function dispatchGenerationJobs(array $createdItems): void
    {
        foreach ($createdItems as $item) {
            if (!$item instanceof ContentItem) {
                continue;
            }

            GenerationExecution::dispatchContentItem((int) $item->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfileData(TenantProfile $profile): array
    {
        return [
            'business_name' => $profile->business_name,
            'industry' => $profile->industry,
            'website' => $profile->website,
            'services' => $profile->services,
            'target' => $profile->target,
            'cta' => $profile->cta,
            'notes' => $profile->notes,
            'vision' => $profile->vision,
            'values' => $profile->values,
            'business_hours' => $profile->business_hours,
            'seasonal_offers' => $profile->seasonal_offers,
            'brand_palette' => $profile->brand_palette,
            'overlay_preferences' => is_array($profile->overlay_preferences) ? $profile->overlay_preferences : [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<int, array<string, mixed>>  $assets
     * @param  array<int, array<string, mixed>>  $assetVariables
     * @return array<string, mixed>
     */
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
}
