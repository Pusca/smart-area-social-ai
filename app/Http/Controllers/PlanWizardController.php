<?php

namespace App\Http\Controllers;

use App\Enums\AiStatus;
use App\Jobs\GeneratePlanTopics;
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
        $profile = TenantProfile::first();

        // Se non ha profilo tenant, lo mando a completarlo (una volta sola)
        if (!$profile) {
            return redirect()->route('profile.brand')
                ->with('status', 'Prima completa il profilo attività (wizard unico).');
        }

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
        $profile = TenantProfile::first();
        $step1 = $request->session()->get('plan.step1', []);

        $planId = $request->session()->get('plan.plan_id');
        $plan = $planId ? ContentPlan::with('items')->find($planId) : null;

        if (!$plan) {
            $plan = ContentPlan::latest('id')->with('items')->first();
        }

        return view('wizard.done', [
            'plan' => $plan,
            'profile' => $profile,
            'step1' => $step1,
        ]);
    }

    /**
     * Genera piano + items. Il contesto brand NON viene copiato nel piano:
     * i job lo leggono live dal TenantProfile al momento della generazione.
     */
    public function generate(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::first();
        if (!$profile) {
            return redirect()->route('profile.brand')->with('status', 'Completa prima il profilo attività.');
        }

        $step1 = $request->session()->get('plan.step1', []);
        if (empty($step1)) {
            return redirect()->route('wizard.start')->with('status', 'Completa prima i dati del piano.');
        }

        $start = Carbon::parse($step1['start_date'])->startOfDay();
        $end = Carbon::parse($step1['end_date'])->startOfDay();

        $postsPerWeek = (int) $step1['posts_per_week'];
        $platforms = $step1['platforms'] ?? ['instagram'];
        $formats = $step1['formats'] ?? ['post'];

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
                ],
            ]);

            // Distribuzione: posts_per_week è A SETTIMANA, spalmati uniformemente sul range
            $daysCount = max(1, (int) $start->diffInDays($end) + 1);
            $weeks = max(1, (int) ceil($daysCount / 7));
            $totalPosts = min($postsPerWeek * $weeks, 90);

            $hours = [9, 12, 17, 19];
            $itemsCreated = 0;

            for ($i = 0; $i < $totalPosts; $i++) {
                $dayOffset = intdiv($i * $daysCount, $totalPosts);
                $hour = $hours[$i % count($hours)];
                $scheduledAt = (clone $start)->addDays($dayOffset)->setTime($hour, 0);

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
                    'hashtags' => [],
                    'assets' => [],
                    'ai_meta' => null,
                    'ai_status' => AiStatus::Queued,
                ]);

                $itemsCreated++;
            }

            DB::commit();

            $request->session()->put('plan.plan_id', $plan->id);

            // Ideazione argomenti a livello piano, poi generazione dei singoli item
            GeneratePlanTopics::dispatch($plan->id);

            return redirect()->route('wizard.done')
                ->with('status', "Piano creato ✅ (ID: {$plan->id}) — Items creati: {$itemsCreated}");
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('wizard.done')->with('status', 'Errore creazione piano ❌: ' . $e->getMessage());
        }
    }
}
