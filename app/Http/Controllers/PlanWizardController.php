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

/**
 * Wizard piano a UN solo passaggio: periodo + obiettivo + cadenza.
 * Tono, piattaforme e formati vengono dal profilo attività (niente doppioni);
 * al submit il piano viene creato e la generazione AI parte subito.
 */
class PlanWizardController extends Controller
{
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
            'posts_per_week' => $profile->default_posts_per_week ?? 5,
        ];

        return view('wizard.start', ['step1' => $defaults, 'profile' => $profile]);
    }

    /**
     * Crea il piano + items e avvia subito la generazione AI.
     * Il contesto brand NON viene copiato nel piano: i job lo leggono live
     * dal TenantProfile al momento della generazione.
     */
    public function store(Request $request)
    {
        $profile = TenantProfile::first();
        if (!$profile) {
            return redirect()->route('profile.brand')->with('status', 'Completa prima il profilo attività.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'goal' => 'required|string|max:500',
            'posts_per_week' => 'required|integer|min:1|max:21',
        ]);

        $user = $request->user();

        $tone = $profile->default_tone ?? 'professionale';
        $platforms = array_values($profile->default_platforms ?: ['instagram']);
        $formats = array_values($profile->default_formats ?: ['post']);

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        $postsPerWeek = (int) $data['posts_per_week'];

        try {
            $plan = DB::transaction(function () use ($user, $profile, $data, $tone, $platforms, $formats, $start, $end, $postsPerWeek) {
                $plan = ContentPlan::create([
                    'tenant_id' => $user->tenant_id,
                    'created_by' => $user->id,
                    'name' => $data['name'],
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => 'draft',
                    'settings' => [
                        'goal' => $data['goal'],
                        'tone' => $tone,
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

                for ($i = 0; $i < $totalPosts; $i++) {
                    $dayOffset = intdiv($i * $daysCount, $totalPosts);
                    $hour = $hours[$i % count($hours)];
                    $scheduledAt = (clone $start)->addDays($dayOffset)->setTime($hour, 0);

                    ContentItem::create([
                        'tenant_id' => $user->tenant_id,
                        'content_plan_id' => $plan->id,
                        'created_by' => $user->id,
                        'platform' => $platforms[$i % count($platforms)],
                        'format' => $formats[$i % count($formats)],
                        'scheduled_at' => $scheduledAt,
                        'status' => 'draft',
                        'title' => Str::limit(($profile->business_name ?? 'Brand') . " — {$data['goal']}", 110, ''),
                        'caption' => null,
                        'hashtags' => [],
                        'assets' => [],
                        'ai_meta' => null,
                        'ai_status' => AiStatus::Queued,
                    ]);
                }

                return $plan;
            });
        } catch (\Throwable $e) {
            return redirect()->route('wizard.start')
                ->with('status', 'Errore creazione piano ❌: ' . $e->getMessage());
        }

        $request->session()->put('plan.plan_id', $plan->id);

        // Ideazione argomenti a livello piano, poi generazione dei singoli item
        GeneratePlanTopics::dispatch($plan->id);

        return redirect()->route('wizard.done')
            ->with('status', "Piano creato ✅ — la generazione AI è partita ({$plan->items()->count()} post)");
    }

    /**
     * Pagina di avanzamento generazione del piano.
     */
    public function done(Request $request)
    {
        $profile = TenantProfile::first();

        $planId = $request->session()->get('plan.plan_id');
        $plan = $planId ? ContentPlan::with('items')->find($planId) : null;

        if (!$plan) {
            $plan = ContentPlan::latest('id')->with('items')->first();
        }

        return view('wizard.done', [
            'plan' => $plan,
            'profile' => $profile,
        ]);
    }
}
