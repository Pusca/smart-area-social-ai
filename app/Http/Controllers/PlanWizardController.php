<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Services\MemoryBuilderService;
use App\Services\StrategyBrainService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanWizardController extends Controller
{
    public function __construct(
        private readonly StrategyBrainService $brain,
        private readonly MemoryBuilderService $memoryBuilder
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

        $preferences = [
            'goal' => $step1['goal'],
            'tone' => $step1['tone'],
            'posts_total' => $postsTotal,
            'platforms' => $platforms,
            'formats' => $formats,
            'date_range' => [$start->toDateString(), $end->toDateString()],
        ];

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

        $strategy = $this->brain->buildStrategy([
            'profile' => $profileData,
            'assets' => $assets,
            'memory' => $memory,
            'preferences' => $preferences,
        ]);

        $blueprints = $this->brain->buildItemBlueprints($strategy, $preferences, $start, $end, $postsTotal);

        try {
            DB::beginTransaction();

            $plan = ContentPlan::create([
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

            foreach ($blueprints as $blueprint) {
                ContentItem::create([
                    'tenant_id' => $user->tenant_id,
                    'content_plan_id' => $plan->id,
                    'created_by' => $user->id,
                    'platform' => $blueprint['platform'],
                    'format' => $blueprint['format'],
                    'scheduled_at' => Carbon::parse($blueprint['scheduled_at']),
                    'status' => 'draft',
                    'title' => Str::limit(
                        $blueprint['title_hint'] ?: (($profile->business_name ?? 'Brand') . ' - ' . $blueprint['angle']),
                        110,
                        ''
                    ),
                    'caption' => null,
                    'hashtags' => [],
                    'assets' => [],
                    'ai_meta' => [
                        'tenant_profile' => $profileData,
                        'brand_assets' => $assets,
                        'plan' => $preferences,
                        'memory_summary' => $memory,
                        'strategy' => [
                            'pillars' => $strategy['pillars'] ?? [],
                            'messaging_map' => $strategy['messaging_map'] ?? [],
                            'hashtag_strategy' => $strategy['hashtag_strategy'] ?? [],
                            'brand_references' => $strategy['brand_references'] ?? [],
                        ],
                        'item_brain' => [
                            'pillar' => $blueprint['pillar'],
                            'angle' => $blueprint['angle'],
                            'objective' => $blueprint['objective'],
                            'key_points' => $blueprint['key_points'],
                            'cta' => $blueprint['cta'],
                            'image_direction' => $blueprint['image_direction'],
                            'avoid_list' => $blueprint['avoid_list'],
                            'campaign' => $blueprint['campaign'],
                            'campaign_step' => $blueprint['campaign_step'],
                        ],
                    ],
                    'ai_status' => 'queued',
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('wizard.done')->with('status', 'Errore creazione piano: ' . $e->getMessage());
        }

        $request->session()->put('plan.plan_id', $plan->id);

        try {
            $itemIds = ContentItem::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('content_plan_id', $plan->id)
                ->pluck('id')
                ->all();

            foreach ($itemIds as $itemId) {
                GenerateAiForContentItem::dispatch((int) $itemId);
            }
        } catch (\Throwable) {
            // best effort
        }

        return redirect()->route('wizard.done')
            ->with('status', "Piano creato (ID: {$plan->id}) con strategia unica e {$postsTotal} item.");
    }
}
