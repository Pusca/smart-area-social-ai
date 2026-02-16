<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlanWizardController extends Controller
{
    /**
     * Step 1 - Wizard start (goal/tone/posts/week + piattaforme ecc.)
     */
    public function start(Request $request)
    {
        $step1 = $request->session()->get('wizard.step1', []);
        return view('wizard.start', compact('step1'));
    }

    /**
     * Salva Step 1 in sessione (SOLO dati serializzabili)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'goal' => 'required|string|max:120',
            'tone' => 'required|string|max:80',
            'posts_per_week' => 'required|integer|min:1|max:21',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:50',
            'formats' => 'nullable|array',
            'formats.*' => 'string|max:50',
        ]);

        // Normalizzazioni
        $data['platforms'] = array_values(array_unique($data['platforms'] ?? ['instagram']));
        $data['formats'] = array_values(array_unique($data['formats'] ?? ['post']));

        $request->session()->put('wizard.step1', $data);

        return redirect()->route('wizard.brand')->with('status', 'Step 1 salvato ✅');
    }

    /**
     * Step 2 - Brand page
     */
    public function brand(Request $request)
    {
        $brand = $request->session()->get('wizard.brand', []);
        $step1 = $request->session()->get('wizard.step1', []);

        return view('wizard.brand', compact('brand', 'step1'));
    }

    /**
     * Salva Brand + Assets:
     * IMPORTANTISSIMO: non mettiamo MAI UploadedFile in sessione.
     * Salviamo i file su storage e in sessione mettiamo SOLO path + meta (stringhe/array).
     */
    public function brandStore(Request $request)
    {
        $data = $request->validate([
            'business_name' => 'required|string|max:120',
            'industry' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',

            // assets opzionali
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:4096',
        ]);

        $user = $request->user();

        $brand = [
            'business_name' => $data['business_name'],
            'industry' => $data['industry'] ?? null,
            'website' => $data['website'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        // Carico su storage (public) e salvo SOLO i path in sessione
        $assets = [];

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $path = $file->store('brand-assets/' . $user->tenant_id . '/logo', 'public');
            $assets[] = [
                'type' => 'logo',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
            ];
        }

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                $path = $img->store('brand-assets/' . $user->tenant_id . '/images', 'public');
                $assets[] = [
                    'type' => 'image',
                    'path' => $path,
                    'original_name' => $img->getClientOriginalName(),
                ];
            }
        }

        // Salvo in sessione SOLO roba serializzabile
        $request->session()->put('wizard.brand', $brand);
        $request->session()->put('wizard.assets', $assets);

        return redirect()->route('wizard.done')->with('status', 'Brand + assets salvati ✅');
    }

    /**
     * Pagina done: se piano non esiste o esiste ma NON ha items -> mostra bottone Genera
     */
    public function done(Request $request)
    {
        $user = $request->user();

        $brand = $request->session()->get('wizard.brand', []);
        $step1 = $request->session()->get('wizard.step1', []);
        $planId = $request->session()->get('wizard.plan_id');

        $plan = null;
        if ($planId) {
            $plan = ContentPlan::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', $planId)
                ->with('items')
                ->first();
        }

        // Se non ho plan_id in sessione, prendo l'ultimo piano del tenant (se c'è)
        if (!$plan) {
            $plan = ContentPlan::query()
                ->where('tenant_id', $user->tenant_id)
                ->latest('id')
                ->with('items')
                ->first();
        }

        return view('wizard.done', compact('plan', 'brand', 'step1'));
    }

    /**
     * Genera piano + items (sempre) + mette in coda la generazione AI (best-effort)
     */
    public function generate(Request $request)
    {
        $user = $request->user();

        $brand = $request->session()->get('wizard.brand', []);
        $step1 = $request->session()->get('wizard.step1', []);
        $assets = $request->session()->get('wizard.assets', []);

        if (empty($brand['business_name'])) {
            return redirect()->route('wizard.brand')->with('status', 'Completa prima il Brand.');
        }
        if (empty($step1['goal']) || empty($step1['tone']) || empty($step1['posts_per_week'])) {
            return redirect()->route('wizard.start')->with('status', 'Completa prima lo Step 1.');
        }

        $postsPerWeek = (int) $step1['posts_per_week'];
        $platforms = $step1['platforms'] ?? ['instagram'];
        $formats = $step1['formats'] ?? ['post'];

        // Piano: prossima settimana (lun-dom)
        $start = Carbon::now()->startOfDay();
        $start = $start->next(Carbon::MONDAY); // prossimo lunedì
        $end = (clone $start)->addDays(6);

        $planName = 'Piano ' . ($brand['business_name'] ?? 'Social') . ' — ' . $start->format('d/m');

        $plan = null;

        DB::beginTransaction();
        try {
            // 1) Crea piano
            $plan = ContentPlan::create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'name' => $planName,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => 'draft',
                'settings' => [
                    'goal' => $step1['goal'],
                    'tone' => $step1['tone'],
                    'posts_per_week' => $postsPerWeek,
                    'platforms' => $platforms,
                    'formats' => $formats,
                    'brand' => $brand,
                    'assets' => $assets,
                ],
            ]);

            // 2) Crea items
            $itemsCreated = 0;

            // Distribuzione semplice: spalmati sulla settimana
            $days = [];
            for ($i = 0; $i < 7; $i++) $days[] = (clone $start)->addDays($i);

            for ($i = 0; $i < $postsPerWeek; $i++) {
                $day = $days[$i % 7];
                // orari alternati
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
                    'title' => Str::limit(($brand['business_name'] ?? 'Brand') . " — {$step1['goal']}", 110, ''),
                    'caption' => null,
                    // IMPORTANT: se il tuo Model non ha casts, qui mettiamo stringhe JSON safe:
                    'hashtags' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'assets' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'ai_meta' => json_encode([
                        'brand' => $brand,
                        'assets' => $assets,
                        'plan' => [
                            'goal' => $step1['goal'],
                            'tone' => $step1['tone'],
                            'posts_per_week' => $postsPerWeek,
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'ai_status' => 'queued',
                ]);

                $itemsCreated++;
            }

            DB::commit();

            // 3) Salva plan_id in sessione e manda in coda (best-effort)
            $request->session()->put('wizard.plan_id', $plan->id);

            // dispatch best-effort (non deve bloccare)
            try {
                $ids = ContentItem::where('content_plan_id', $plan->id)->pluck('id')->all();
                foreach ($ids as $id) {
                    GenerateAiForContentItem::dispatch((int)$id);
                }
            } catch (\Throwable $e) {
                // non bloccare la UX
            }

            return redirect()->route('wizard.done')->with('status',
                "Piano creato ✅ (ID: {$plan->id}) — Items creati: {$itemsCreated}"
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('wizard.done')->with('status', 'Errore creazione piano ❌: ' . $e->getMessage());
        }
    }
}
