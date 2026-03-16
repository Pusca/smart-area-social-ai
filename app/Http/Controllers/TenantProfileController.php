<?php

namespace App\Http\Controllers;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentPlan;
use App\Models\EditorialStrategy;
use App\Models\TenantProfile;
use App\Services\AssetVariableService;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\GuidedAssetVariableService;
use App\Services\Onboarding\QuickstartOnboardingService;
use App\Services\TenantQuotaService;
use App\Support\GenerationExecution;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantProfileController extends Controller
{
    public function __construct(
        private readonly EditorialStrategyService $editorialStrategyService,
        private readonly AssetVariableService $assetVariableService,
        private readonly GuidedAssetVariableService $guidedAssetVariableService,
        private readonly QuickstartOnboardingService $quickstartOnboardingService,
        private readonly TenantQuotaService $tenantQuotaService
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $strategy = $this->editorialStrategyService->forTenant($tenantId);
        if (!$strategy && $profile) {
            $strategy = $this->editorialStrategyService->refreshForTenant($tenantId, $profile);
        }

        $assets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->get();

        $assetVariables = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->latest('id')
            ->get();

        $assetVariableCatalog = $this->assetVariableService->catalogForTenant($tenantId);
        $demoPlan = $this->resolveQuickstartDemoPlan($tenantId, $profile);
        $isOnboardingPending = !$profile || !$profile->onboarding_completed_at;
        $quickstartDismissed = (bool) ($profile?->quickstart_dismissed_at);
        $shouldShowQuickstart = !$quickstartDismissed && ($isOnboardingPending || !$profile?->quickstart_generated_at || $demoPlan !== null);

        return view('wizard.brand', compact(
            'profile',
            'assets',
            'strategy',
            'assetVariables',
            'assetVariableCatalog',
            'demoPlan',
            'isOnboardingPending',
            'quickstartDismissed',
            'shouldShowQuickstart'
        ));
    }

    public function storeQuickstart(Request $request)
    {
        $data = $request->validate([
            'business_name' => 'required|string|max:120',
            'industry' => 'required|string|max:120',
            'website' => 'nullable|string|max:255',
            'services' => 'required|string|max:2000',
            'target' => 'required|string|max:2000',
            'cta' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'default_goal' => 'nullable|string|max:500',
            'default_tone' => 'nullable|string|max:80',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'images' => 'nullable|array|max:8',
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:4096',
            'quickstart_variable_name' => 'nullable|string|max:120',
            'quickstart_variable_kind' => 'nullable|string|in:person,location,product,custom',
            'quickstart_variable_description' => 'nullable|string|max:255',
        ]);

        try {
            $this->tenantQuotaService->assertCanCreateContentItems((int) $request->user()->tenant_id, 3);
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->withErrors(['quickstart' => $e->getMessage()]);
        }

        try {
            $result = $this->quickstartOnboardingService->runQuickstart(
                user: $request->user(),
                data: $data,
                logo: $request->file('logo'),
                images: (array) $request->file('images', [])
            );

            $count = count($result['created_items'] ?? []);

            if (GenerationExecution::shouldShowProgressPage()) {
                return redirect()->route('plans.generating', [
                    'contentPlan' => $result['plan'],
                    'context' => 'quickstart',
                ]);
            }

            return redirect()
                ->route('profile.brand')
                ->with('status', "Prova pronta: creato un calendario demo di 7 giorni con {$count} contenuti. Puoi salvarlo nel workspace, rigenerarlo o eliminarlo quando vuoi.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['quickstart' => $e->getMessage()]);
        }
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

            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $path = $file->store($baseDir . '/logo', 'public');

                BrandAsset::query()->create([
                    'tenant_id' => $tenantId,
                    'content_plan_id' => null,
                    'kind' => 'logo',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ]);
            }

            if ($request->hasFile('images')) {
                foreach ((array) $request->file('images') as $img) {
                    $path = $img->store($baseDir . '/images', 'public');

                    BrandAsset::query()->create([
                        'tenant_id' => $tenantId,
                        'content_plan_id' => null,
                        'kind' => 'image',
                        'path' => $path,
                        'original_name' => $img->getClientOriginalName(),
                        'size' => $img->getSize(),
                        'mime' => $img->getMimeType(),
                    ]);
                }
            }

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

    public function regenerateQuickstartDemo(Request $request)
    {
        try {
            $result = $this->quickstartOnboardingService->regenerateQuickstart($request->user());
            $count = count($result['created_items'] ?? []);

            if (GenerationExecution::shouldShowProgressPage()) {
                return redirect()->route('plans.generating', [
                    'contentPlan' => $result['plan'],
                    'context' => 'quickstart',
                ]);
            }

            return redirect()
                ->route('profile.brand')
                ->with('status', "Demo rigenerata: trovati {$count} contenuti aggiornati per la prossima settimana.");
        } catch (\Throwable $e) {
            return redirect()
                ->route('profile.brand')
                ->with('status', 'Impossibile rigenerare la demo: ' . $e->getMessage());
        }
    }

    public function saveQuickstartDemo(Request $request)
    {
        try {
            $savedPlans = $this->quickstartOnboardingService->saveQuickstartDemo($request->user());
            $message = $savedPlans > 0
                ? 'Demo iniziale salvata nel workspace. I contenuti restano disponibili e il quickstart e stato chiuso.'
                : 'Non ho trovato una demo iniziale attiva da salvare.';

            return redirect()->route('profile.brand')->with('status', $message);
        } catch (\Throwable $e) {
            return redirect()
                ->route('profile.brand')
                ->with('status', 'Impossibile salvare la demo iniziale: ' . $e->getMessage());
        }
    }

    public function destroyQuickstartDemo(Request $request)
    {
        $deletedPlans = $this->quickstartOnboardingService->deleteQuickstartDemo($request->user());
        $message = $deletedPlans > 0
            ? 'Demo iniziale eliminata e quickstart chiuso. I file demo sono stati rimossi.'
            : 'Nessuna demo iniziale da eliminare.';

        return redirect()->route('profile.brand')->with('status', $message);
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

        foreach ($assets as $asset) {
            if ($asset->path) {
                Storage::disk('public')->delete($asset->path);
            }
            $asset->delete();
        }

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

        if ($asset->path) {
            Storage::disk('public')->delete($asset->path);
        }

        $asset->delete();

        return redirect()->route('profile.brand')->with('status', 'Asset eliminato');
    }

    public function storeVariable(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'kind' => 'required|string|in:person,location,product,custom',
            'description' => 'nullable|string|max:1000',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'integer',
        ]);

        $assetIds = $this->assetVariableService->sanitizeAssetIdsForTenant($tenantId, (array) ($data['asset_ids'] ?? []));
        if (empty($assetIds)) {
            return redirect()
                ->route('profile.brand')
                ->with('status', 'Seleziona almeno 1 asset valido per creare la variabile.');
        }

        $slug = $this->assetVariableService->buildUniqueSlugForTenant($tenantId, (string) $data['name']);

        AssetVariable::query()->create([
            'tenant_id' => $tenantId,
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'kind' => (string) $data['kind'],
            'description' => trim((string) ($data['description'] ?? '')),
            'asset_ids' => $assetIds,
            'is_active' => true,
        ]);

        return redirect()->route('profile.brand')->with('status', 'Variabile asset creata.');
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

        return redirect()
            ->route('profile.brand')
            ->with('status', "Persona pack creato: {$result['variable']->name} con {$assetCount} riferimenti pronti per immagini e video.");
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

    private function resolveQuickstartDemoPlan(int $tenantId, ?TenantProfile $profile): ?ContentPlan
    {
        $planQuery = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->withCount('items')
            ->with(['items' => fn ($q) => $q->orderBy('scheduled_at')->orderBy('id')]);

        $planId = (int) ($profile?->quickstart_last_plan_id ?? 0);
        if ($planId > 0) {
            $plan = (clone $planQuery)->where('id', $planId)->first();
            if ($plan) {
                return $plan;
            }
        }

        return (clone $planQuery)
            ->latest('id')
            ->get()
            ->first(fn (ContentPlan $plan) => data_get($plan->settings, 'mode') === 'onboarding_quickstart_demo');
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
}
