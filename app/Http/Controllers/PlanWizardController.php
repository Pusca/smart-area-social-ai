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

    /**
     * ✅ ORA: salva SOLO brand in sessione e poi va a done (senza generare).
     */
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

        // ✅ Non generiamo qui. Andiamo a una pagina di conferma con bottone "Genera".
        return redirect()->route('wizard.done')->with('status', 'Asset brand salvati ✅');
    }

    /**
     * ✅ Nuovo endpoint: parte la generazione del piano quando premi il bottone in done.
     */
    public function generate(Request $request)
    {
        // CSRF ok, route POST
        return $this->finalize($request);
    }

    /**
     * Genera piano + items + dispatch job.
     */
    public function finalize(Request $request)
    {
        $user = Auth::user();
        $tenantId = (int) $user->tenant_id;

        $step1 = session('wizard_step1', []);
        $brand = session('wizard_brand', []);

        // se manca qualcosa, rimandiamo al wizard
        if (empty($step1) || empty($brand) || empty($brand['business_name'])) {
            return redirect()->route('wizard.start')->with('status', 'Completa prima il wizard (Step 1 + Brand) ✅');
        }

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

        // pulizia sessione wizard e salva plan_id per done
        session()->forget(['wizard_step1', 'wizard_brand']);
        session()->put('plan_id', $plan->id);

        return redirect()->route('wizard.done')->with('status', 'Piano creato e messo in coda ✅');
    }

    public function done(Request $request)
    {
        // ✅ ora plan_id lo teniamo in sessione e in più posso mostrare anche lo stato “pre-generazione”
        $planId = $request->session()->get('plan_id');
        $plan = $planId ? ContentPlan::with('items')->find($planId) : null;

        // Mostriamo anche i dati raccolti dal wizard se ancora non hai generato
        $step1 = session('wizard_step1', []);
        $brand = session('wizard_brand', []);

        return view('wizard.done', [
            'plan' => $plan,
            'step1' => $step1,
            'brand' => $brand,
        ]);
    }
}
