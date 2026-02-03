<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PlanWizardController extends Controller
{
    public function start()
    {
        return view('wizard.start');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'goal' => ['required', 'string', 'max:200'],
            'tone' => ['required', 'string', 'max:100'],
            'posts_per_week' => ['required', 'integer', 'min:1', 'max:14'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string'],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => ['string'],
        ]);

        session()->put('wizard_step1', $data);

        return redirect()->route('wizard.brand');
    }

    public function brand()
    {
        return view('wizard.brand');
    }

    public function brandStore(Request $request)
    {
        $brand = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:255'],
            'services' => ['nullable', 'string', 'max:800'],
            'target' => ['nullable', 'string', 'max:800'],
            'cta' => ['nullable', 'string', 'max:200'],
            // ridondanti se arrivano da step1, ma li accettiamo per sicurezza:
            'goal' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'max:100'],
            'posts_per_week' => ['nullable'],
            'platforms' => ['nullable', 'array'],
            'formats' => ['nullable', 'array'],
        ]);

        session()->put('wizard_brand', $brand);

        return $this->finalize($request);
    }

    public function finalize(Request $request)
    {
        $user = Auth::user();
        $tenantId = (int) $user->tenant_id;

        $step1 = session('wizard_step1', []);
        $brand = session('wizard_brand', []);

        // merge: step1 ha priorità, brand completa
        $goal = $step1['goal'] ?? ($brand['goal'] ?? 'Lead');
        $tone = $step1['tone'] ?? ($brand['tone'] ?? 'professionale');
        $postsPerWeek = (int) ($step1['posts_per_week'] ?? ($brand['posts_per_week'] ?? 5));
        $platforms = $step1['platforms'] ?? ($brand['platforms'] ?? ['instagram']);
        $formats = $step1['formats'] ?? ($brand['formats'] ?? ['post']);

        // Piano settimana corrente (lun-dom) a partire da oggi
        $start = Carbon::now()->startOfWeek();
        $end = (clone $start)->addDays(6)->endOfDay();

        $plan = ContentPlan::create([
            'tenant_id' => $tenantId,
            'created_by' => $user->id,
            'name' => 'Piano ' . Carbon::now()->format('F Y'),
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'settings' => [
                'goal' => $goal,
                'tone' => $tone,
                'platforms' => $platforms,
                'formats' => $formats,
                'posts_per_week' => $postsPerWeek,
                'brand' => [
                    'business_name' => $brand['business_name'] ?? '',
                    'industry' => $brand['industry'] ?? '',
                    'services' => $brand['services'] ?? '',
                    'target' => $brand['target'] ?? '',
                    'cta' => $brand['cta'] ?? '',
                ],
            ],
        ]);

        // Crea N items distribuiti nei prossimi 7 giorni
        $items = [];
        for ($i = 0; $i < $postsPerWeek; $i++) {
            $dayOffset = (int) floor(($i * 7) / max(1, $postsPerWeek));
            $scheduledAt = (clone $start)->addDays($dayOffset)->setTime(10, 0, 0);

            $platform = $platforms[$i % count($platforms)];
            $format = $formats[$i % count($formats)];

            $title = "Bozza #" . ($i + 1) . " — " . ucfirst($platform);

            $item = new ContentItem();
            $item->tenant_id = $tenantId;
            $item->content_plan_id = $plan->id;
            $item->created_by = $user->id;

            $item->platform = $platform;
            $item->format = $format;
            $item->scheduled_at = $scheduledAt;

            $item->status = 'draft';
            $item->title = $title;

            // campi "base" (placeholder)
            $item->caption = "Brand: " . ($brand['business_name'] ?? '') . " | Obiettivo: {$goal} | Tone: {$tone} | CTA: " . ($brand['cta'] ?? '—') . " (Placeholder: AI)";
            $item->hashtags = []; // verranno riempiti da AI
            $item->ai_meta = [
                'plan' => [
                    'goal' => $goal,
                    'tone' => $tone,
                ],
                'brand' => [
                    'business_name' => $brand['business_name'] ?? '',
                    'industry' => $brand['industry'] ?? '',
                    'services' => $brand['services'] ?? '',
                    'target' => $brand['target'] ?? '',
                    'cta' => $brand['cta'] ?? '',
                ],
                'ai' => [
                    'status' => 'pending',
                ],
            ];

            // campi AI veri
            $item->ai_status = 'queued';
            $item->ai_error = null;

            $item->save();
            $items[] = $item;
        }

        // dispatch generazione AI (queue)
        foreach ($items as $it) {
            GenerateAiForContentItem::dispatch($it->id);
        }

        session()->forget(['wizard_step1', 'wizard_brand']);

        return redirect()->route('wizard.done')->with('plan_id', $plan->id);
    }

    public function done(Request $request)
    {
        $planId = $request->session()->get('plan_id');
        $plan = $planId ? ContentPlan::with('items')->find($planId) : null;

        return view('wizard.done', [
            'plan' => $plan,
        ]);
    }
}
