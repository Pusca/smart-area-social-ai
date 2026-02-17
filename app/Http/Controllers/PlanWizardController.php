<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanWizardController extends Controller
{
    /**
     * Step 1 - Creazione piano (nome + date + override contenuti)
     * Precompila dai default del TenantProfile.
     */
    public function start(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();

        // Se non ha profilo tenant, lo mando a completarlo (una volta sola)
        if (!$profile) {
            return redirect()->route('profile.brand')
                ->with('status', 'Prima completa il profilo attività (wizard unico).');
        }

        // Prefill
        $defaults = [
            'name' => 'Piano ' . ($profile->business_name ?? 'Social AI') . ' — ' . Carbon::now()->format('d/m'),
            'start_date' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
            'end_date' => Carbon::now()->next(Carbon::MONDAY)->copy()->addDays(6)->toDateString(),

            'goal' => $profile->default_goal ?? 'Lead + Awareness + Autorità',
            'tone' => $profile->default_tone ?? 'professionale',
            'posts_per_week' => $profile->default_posts_per_week ?? 5,
            'platforms' => $profile->default_platforms ?? ['instagram'],
            'formats' => $profile->default_formats ?? ['post'],
        ];

        $step1 = array_merge($defaults, $request->session()->get('plan.step1', []));

        return view('wizard.start', compact('step1', 'profile'));
    }

    /**
     * Salva Step 1 piano in sessione
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',

            'goal' => 'required|string|max:120',
            'tone' => 'required|string|max:80',
            'posts_per_week' => 'required|integer|min:1|max:21',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:50',
            'formats' => 'nullable|array',
            'formats.*' => 'string|max:50',
        ]);

        $data['platforms'] = array_values(array_unique($data['platforms'] ?? ['instagram']));
        $data['formats'] = array_values(array_unique($data['formats'] ?? ['post']));

        $request->session()->put('plan.step1', $data);

        return redirect()->route('wizard.done')->with('status', 'Dati piano salvati ✅ Ora puoi generare.');
    }

    /**
     * Pagina done: mostra bottone "Genera" se piano non esiste o non ha items
     */
    public function done(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();

        // step1 piano
        $step1 = $request->session()->get('plan.step1', []);

        // ultimo piano del tenant (per preview)
        $planId = $request->session()->get('plan.plan_id');
        $plan = null;

        if ($planId) {
            $plan = ContentPlan::where('tenant_id', $user->tenant_id)
                ->where('id', $planId)
                ->with('items')
                ->first();
        }

        if (!$plan) {
            $plan = ContentPlan::where('tenant_id', $user->tenant_id)
                ->latest('id')
                ->with('items')
                ->first();
        }

        return view('wizard.done', [
            'plan' => $plan,
            'profile' => $profile,
            'step1' => $step1,
        ]);
    }

    /**
     * Genera piano + items, usando TenantProfile + override step1
     */
    public function generate(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();
        if (!$profile) {
            return redirect()->route('profile.brand')->with('status', 'Completa prima il profilo attività.');
        }

        $step1 = $request->session()->get('plan.step1', []);
        if (empty($step1)) {
            return redirect()->route('wizard.start')->with('status', 'Completa prima i dati del piano.');
        }

        $start = Carbon::parse($step1['start_date'])->startOfDay();
        $end = Carbon::parse($step1['end_date'])->startOfDay();

        $postsPerWeek = (int)$step1['posts_per_week'];
        $platforms = $step1['platforms'] ?? ['instagram'];
        $formats = $step1['formats'] ?? ['post'];

        // assets base tenant
        $assets = BrandAsset::where('tenant_id', $user->tenant_id)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->get()
            ->map(fn($a) => [
                'kind' => $a->kind,
                'path' => $a->path,
                'original_name' => $a->original_name,
            ])->values()->all();

        $plan = null;
        $itemsCreated = 0;

        DB::beginTransaction();
        try {
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
                    'posts_per_week' => $postsPerWeek,
                    'platforms' => $platforms,
                    'formats' => $formats,

                    'tenant_profile' => [
                        'business_name' => $profile->business_name,
                        'industry' => $profile->industry,
                        'website' => $profile->website,
                        'services' => $profile->services,
                        'target' => $profile->target,
                        'cta' => $profile->cta,
                        'notes' => $profile->notes,
                    ],
                    'assets' => $assets,
                ],
            ]);

            // Distribuzione: spalmata nei giorni del range
            $daysCount = max(1, $start->diffInDays($end) + 1);
            $days = [];
            for ($i = 0; $i < $daysCount; $i++) {
                $days[] = (clone $start)->addDays($i);
            }

            for ($i = 0; $i < $postsPerWeek; $i++) {
                $day = $days[$i % $daysCount];
                $hour = ($i % 2 === 0) ? 10 : 17;
                $scheduledAt = (clone $day)->setTime($hour, 0);

                $platform = $platforms[$i % max(1, count($platforms))] ?? 'instagram';
                $format = $formats[$i % max(1, count($formats))] ?? 'post';

                ContentItem::create([
                    'tenant_id' => $user->tenant_id,
                    'content_plan_id' => $plan->id,
                    'created_by' => $user->id,
                    'platform' => $platform,
                    'format' => $format,
                    'scheduled_at' => $scheduledAt,
                    'status' => 'draft',
                    'title' => Str::limit(($profile->business_name ?? 'Brand') . " — {$step1['goal']}", 110, ''),
                    'caption' => null,
                    'hashtags' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'assets' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'ai_meta' => json_encode([
                        'tenant_profile' => [
                            'business_name' => $profile->business_name,
                            'industry' => $profile->industry,
                            'website' => $profile->website,
                            'services' => $profile->services,
                            'target' => $profile->target,
                            'cta' => $profile->cta,
                            'notes' => $profile->notes,
                        ],
                        'assets' => $assets,
                        'plan' => [
                            'goal' => $step1['goal'],
                            'tone' => $step1['tone'],
                            'posts_per_week' => $postsPerWeek,
                            'platforms' => $platforms,
                            'formats' => $formats,
                            'date_range' => [$start->toDateString(), $end->toDateString()],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'ai_status' => 'queued',
                ]);

                $itemsCreated++;
            }

            DB::commit();

            $request->session()->put('plan.plan_id', $plan->id);

            // best-effort queue dispatch
            try {
                $ids = ContentItem::where('content_plan_id', $plan->id)->pluck('id')->all();
                foreach ($ids as $id) {
                    GenerateAiForContentItem::dispatch((int)$id);
                }
            } catch (\Throwable $e) {}

            return redirect()->route('wizard.done')
                ->with('status', "Piano creato ✅ (ID: {$plan->id}) — Items creati: {$itemsCreated}");

        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('wizard.done')->with('status', 'Errore creazione piano ❌: ' . $e->getMessage());
        }
    }
}



