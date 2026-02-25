<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\ContentHistoryAnalyzer;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\MemoryBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class PlanWizardController extends Controller
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly EditorialStrategyService $editorialStrategyService,
        private readonly ContentHistoryAnalyzer $historyAnalyzer,
        private readonly EditorialPlanBuilder $editorialPlanBuilder,
        private readonly ContentGenerator $contentGenerator
    ) {
    }

    public function start(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();
        if (!$profile) {
            return redirect()->route('profile.brand')
                ->with('status', 'Prima completa il profilo attivita.');
        }

        $defaults = [
            'name' => 'Piano ' . ($profile->business_name ?? 'Social AI') . ' - ' . Carbon::now()->format('d/m'),
            'start_date' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
            'end_date' => Carbon::now()->next(Carbon::MONDAY)->copy()->addDays(6)->toDateString(),
            'goal' => $profile->default_goal ?? 'Lead + Awareness + Autorita',
            'tone' => $profile->default_tone ?? 'professionale',
            'posts_per_week' => $profile->default_posts_per_week ?? 5,
            'platforms' => $profile->default_platforms ?? ['instagram'],
            'formats' => $profile->default_formats ?? ['post'],
        ];

        $step1 = array_merge($defaults, $request->session()->get('plan.step1', []));

        return view('wizard.start', compact('step1', 'profile'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'goal' => 'required|string|max:300',
            'tone' => 'required|string|max:80',
            'posts_per_week' => 'required|integer|min:1|max:31',
            'platforms' => 'nullable|array|min:1',
            'platforms.*' => 'string|max:50',
            'formats' => 'nullable|array|min:1',
            'formats.*' => 'string|max:50',
        ]);

        $data['platforms'] = array_values(array_unique($data['platforms'] ?? ['instagram']));
        $data['formats'] = array_values(array_unique($data['formats'] ?? ['post']));

        $request->session()->put('plan.step1', $data);

        return redirect()->route('wizard.done')->with('status', 'Dati piano salvati. Ora puoi generare.');
    }

    public function done(Request $request)
    {
        $user = $request->user();
        $step1 = $request->session()->get('plan.step1', []);

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();

        $planId = $request->session()->get('plan.plan_id');
        $planQuery = ContentPlan::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['items' => fn ($q) => $q->orderBy('scheduled_at')->orderBy('id')]);

        $plan = $planId
            ? (clone $planQuery)->where('id', $planId)->first()
            : null;

        if (!$plan) {
            $plan = (clone $planQuery)->latest('id')->first();
        }

        $strategy = $plan?->strategy ?: data_get($plan?->settings, 'strategy', null);
        $itemStats = [
            'total' => $plan?->items->count() ?? 0,
            'queued' => $plan?->items->whereIn('ai_status', ['queued', 'pending'])->count() ?? 0,
            'done' => $plan?->items->where('ai_status', 'done')->count() ?? 0,
            'error' => $plan?->items->where('ai_status', 'error')->count() ?? 0,
        ];

        $canGenerate = $profile && !empty($step1);

        return view('wizard.done', [
            'plan' => $plan,
            'profile' => $profile,
            'step1' => $step1,
            'strategy' => $strategy,
            'itemStats' => $itemStats,
            'canGenerate' => $canGenerate,
        ]);
    }

    public function progress(Request $request, ?ContentPlan $contentPlan = null)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $plan = $contentPlan;
        if ($plan) {
            if ((int) $plan->tenant_id !== $tenantId) {
                abort(403);
            }
        } else {
            $plan = ContentPlan::query()
                ->where('tenant_id', $tenantId)
                ->latest('id')
                ->first();
        }

        if (!$plan) {
            return response()->json([
                'has_plan' => false,
                'active' => false,
                'counts' => [
                    'total' => 0,
                    'queued' => 0,
                    'pending' => 0,
                    'done' => 0,
                    'error' => 0,
                ],
            ]);
        }

        $counts = [
            'total' => ContentItem::query()->where('content_plan_id', $plan->id)->count(),
            'queued' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'queued')->count(),
            'pending' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'pending')->count(),
            'done' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'done')->count(),
            'error' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'error')->count(),
        ];

        // In ambiente locale drena 1 job per poll, così non resta bloccato in queued se manca worker persistente.
        if (app()->environment('local') && (($counts['queued'] + $counts['pending']) > 0)) {
            try {
                Artisan::call('queue:work', [
                    'connection' => 'database',
                    '--queue' => 'default',
                    '--once' => true,
                    '--tries' => 1,
                    '--timeout' => 180,
                ]);
            } catch (\Throwable) {
                // best effort
            }

            $counts = [
                'total' => ContentItem::query()->where('content_plan_id', $plan->id)->count(),
                'queued' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'queued')->count(),
                'pending' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'pending')->count(),
                'done' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'done')->count(),
                'error' => ContentItem::query()->where('content_plan_id', $plan->id)->where('ai_status', 'error')->count(),
            ];
        }

        return response()->json([
            'has_plan' => true,
            'plan_id' => (int) $plan->id,
            'active' => (($counts['queued'] + $counts['pending']) > 0),
            'completed' => ($counts['total'] > 0 && ($counts['queued'] + $counts['pending']) === 0),
            'counts' => $counts,
            'completed_at' => now()->toDateTimeString(),
        ]);
    }

    public function generate(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();
        if (!$profile) {
            return redirect()->route('profile.brand')->with('status', 'Completa prima il profilo attivita.');
        }

        $step1 = $request->session()->get('plan.step1', []);
        if (empty($step1)) {
            return redirect()->route('wizard.start')->with('status', 'Completa prima i dati del piano.');
        }

        $start = Carbon::parse($step1['start_date'])->startOfDay();
        $end = Carbon::parse($step1['end_date'])->endOfDay();
        $postsTotal = max(1, (int) ($step1['posts_per_week'] ?? 5));
        $platforms = array_values($step1['platforms'] ?? ['instagram']);
        $formats = array_values($step1['formats'] ?? ['post']);
        $refreshFutureOnly = $request->boolean('refresh_future_only');
        $overrideFuture = $request->boolean('override_future');

        $assets = BrandAsset::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->limit(24)
            ->get()
            ->map(fn ($asset) => [
                'id' => $asset->id,
                'kind' => $asset->kind,
                'path' => $asset->path,
                'original_name' => $asset->original_name,
                'mime' => $asset->mime,
            ])
            ->values()
            ->all();

        $memory = $this->memoryBuilder->buildForTenant((int) $user->tenant_id, 40);

        $profileData = [
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
        ];

        $strategyModel = $this->editorialStrategyService->refreshForTenant((int) $user->tenant_id, $profile);
        $strategy = [
            'brand_voice' => $strategyModel->brand_voice ?? [],
            'pillars' => $strategyModel->pillars ?? [],
            'rubrics' => $strategyModel->rubrics ?? [],
            'cta_rules' => $strategyModel->cta_rules ?? [],
            'constraints' => $strategyModel->constraints ?? [],
            'brand_references' => $this->buildBrandReferences($profileData, $assets),
        ];

        $history = $this->historyAnalyzer->snapshot(
            (int) $user->tenant_id,
            (int) config('editorial.history_limit', 120)
        );

        $period = [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'total_posts' => $postsTotal,
        ];

        $itemsToGenerate = [];
        $newPlan = null;

        try {
            if ($refreshFutureOnly) {
                $planId = $request->session()->get('plan.plan_id');
                $plan = ContentPlan::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->when($planId, fn ($q) => $q->where('id', $planId))
                    ->latest('id')
                    ->first();

                if (!$plan) {
                    $newPlan = ContentPlan::create([
                        'tenant_id' => $user->tenant_id,
                        'created_by' => $user->id,
                        'name' => $step1['name'],
                        'start_date' => $start->toDateString(),
                        'end_date' => $end->toDateString(),
                        'status' => 'draft',
                        'settings' => [
                            'goal' => $step1['goal'],
                            'tone' => $step1['tone'],
                            'posts_total' => $postsTotal,
                            'platforms' => $platforms,
                            'formats' => $formats,
                            'tenant_profile' => $profileData,
                            'assets' => $assets,
                            'memory' => $memory,
                            'strategy' => $strategy,
                        ],
                        'strategy' => $strategy,
                    ]);
                    $plan = $newPlan;
                } else {
                    if ($overrideFuture) {
                        ContentItem::query()
                            ->where('tenant_id', $user->tenant_id)
                            ->where('content_plan_id', $plan->id)
                            ->where('scheduled_at', '>=', now())
                            ->delete();
                    }

                    $plan->update([
                        'name' => $step1['name'],
                        'start_date' => $start->toDateString(),
                        'end_date' => $end->toDateString(),
                        'settings' => array_merge((array) $plan->settings, [
                            'goal' => $step1['goal'],
                            'tone' => $step1['tone'],
                            'posts_total' => $postsTotal,
                            'platforms' => $platforms,
                            'formats' => $formats,
                            'tenant_profile' => $profileData,
                            'assets' => $assets,
                            'memory' => $memory,
                            'strategy' => $strategy,
                        ]),
                        'strategy' => $strategy,
                    ]);
                }

                $existingFutureCount = ContentItem::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('content_plan_id', $plan->id)
                    ->where('scheduled_at', '>=', now())
                    ->count();

                $missing = max(0, $postsTotal - $existingFutureCount);
                if ($missing > 0) {
                    $futureStart = now()->greaterThan($start) ? now()->startOfDay() : $start;
                    $itemsToGenerate = $this->editorialPlanBuilder->buildPlan(
                        tenantId: (int) $user->tenant_id,
                        strategy: $strategy,
                        history: $history,
                        period: [
                            'start' => $futureStart->toDateString(),
                            'end' => $end->toDateString(),
                            'total_posts' => $missing,
                        ],
                        options: ['platforms' => $platforms, 'formats' => $formats]
                    );
                }

                if (!empty($itemsToGenerate)) {
                    $this->contentGenerator->generateForPlan($plan, $itemsToGenerate, [
                        'user_id' => (int) $user->id,
                        'profile_data' => $profileData,
                        'strategy' => $strategy,
                        'memory' => $memory,
                        'assets' => $assets,
                    ]);
                }
            } else {
                $newPlan = ContentPlan::create([
                    'tenant_id' => $user->tenant_id,
                    'created_by' => $user->id,
                    'name' => $step1['name'],
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => 'draft',
                    'settings' => [
                        'goal' => $step1['goal'],
                        'tone' => $step1['tone'],
                        'posts_total' => $postsTotal,
                        'platforms' => $platforms,
                        'formats' => $formats,
                        'tenant_profile' => $profileData,
                        'assets' => $assets,
                        'memory' => $memory,
                        'strategy' => $strategy,
                    ],
                    'strategy' => $strategy,
                ]);

                $itemsToGenerate = $this->editorialPlanBuilder->buildPlan(
                    tenantId: (int) $user->tenant_id,
                    strategy: $strategy,
                    history: $history,
                    period: $period,
                    options: ['platforms' => $platforms, 'formats' => $formats]
                );

                $this->contentGenerator->generateForPlan($newPlan, $itemsToGenerate, [
                    'user_id' => (int) $user->id,
                    'profile_data' => $profileData,
                    'strategy' => $strategy,
                    'memory' => $memory,
                    'assets' => $assets,
                ]);
            }
        } catch (\Throwable $e) {
            return redirect()->route('wizard.done')->with('status', 'Errore creazione piano: ' . $e->getMessage());
        }

        $plan = $newPlan ?: ContentPlan::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest('id')
            ->first();

        if (!$plan) {
            return redirect()->route('wizard.done')->with('status', 'Nessun piano disponibile dopo la generazione.');
        }

        $request->session()->put('plan.plan_id', $plan->id);

        try {
            $itemIds = ContentItem::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('content_plan_id', $plan->id)
                ->where('ai_status', 'queued')
                ->pluck('id')
                ->all();

            foreach ($itemIds as $itemId) {
                GenerateAiForContentItem::dispatch((int) $itemId);
            }
        } catch (\Throwable) {
            // best effort
        }

        return redirect()->route('wizard.done')
            ->with('status', "Piano aggiornato (ID: {$plan->id}) con logica editoriale avanzata e anti-duplicati.");
    }

    private function buildBrandReferences(array $profileData, array $assets): array
    {
        $logo = null;
        $images = [];

        foreach ($assets as $asset) {
            if (($asset['kind'] ?? null) === 'logo' && $logo === null) {
                $logo = $asset['path'] ?? null;
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
        ];
    }
}
