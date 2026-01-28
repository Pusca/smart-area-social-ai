<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Services\OpenAIClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PlanWizardController extends Controller
{
    public function start(Request $request)
    {
        $tz = config('app.timezone', 'Europe/Rome');

        $defaults = [
            'name' => 'Piano ' . now($tz)->locale('it')->isoFormat('MMMM YYYY'),
            'goal' => 'lead',
            'tone' => 'professionale',
            'platforms' => ['instagram'],
            'formats' => ['post'],
            'posts_per_week' => 3,
            'start_date' => now($tz)->startOfWeek(Carbon::MONDAY)->toDateString(),
        ];

        return view('wizard.start', compact('defaults'));
    }

    // Step 1: salva in sessione e vai allo step 2
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'goal' => 'required|string|max:40',
            'tone' => 'required|string|max:40',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|max:30',
            'formats' => 'required|array|min:1',
            'formats.*' => 'string|max:30',
            'posts_per_week' => 'required|integer|min:1|max:14',
            'start_date' => 'required|date',
        ]);

        session(['wizard_step1' => $data]);

        return redirect()->route('wizard.brand');
    }

    // Step 2: brand kit
    public function brand(Request $request)
    {
        $step1 = session('wizard_step1');
        if (!$step1) {
            return redirect()->route('wizard.start');
        }

        $defaults = [
            'business_name' => 'Smartera',
            'industry' => 'Digital agency / AI',
            'services' => 'Siti web, Web app, Marketing, Automazioni, Chatbot',
            'target' => 'PMI, professionisti, attività locali',
            'geo' => 'Italia (Veneto)',
            'cta' => 'Richiedi una consulenza',
            'keywords' => 'automazione, AI, crescita, strategia, risultati',
            'avoid' => 'troppo tecnico, frasi lunghe',
        ];

        return view('wizard.brand', compact('defaults', 'step1'));
    }

    public function brandStore(Request $request)
    {
        $step1 = session('wizard_step1');
        if (!$step1) {
            return redirect()->route('wizard.start');
        }

        $brand = $request->validate([
            'business_name' => 'required|string|max:80',
            'industry' => 'required|string|max:120',
            'services' => 'required|string|max:500',
            'target' => 'required|string|max:200',
            'geo' => 'nullable|string|max:120',
            'cta' => 'required|string|max:120',
            'keywords' => 'nullable|string|max:300',
            'avoid' => 'nullable|string|max:300',
        ]);

        session(['wizard_brand' => $brand]);

        return $this->finalize($request);
    }

    // Finalizzazione: crea piano + items + genera AI
    private function finalize(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $tz = config('app.timezone', 'Europe/Rome');

        $step1 = session('wizard_step1');
        $brand = session('wizard_brand');

        if (!$step1 || !$brand) {
            return redirect()->route('wizard.start');
        }

        $start = Carbon::parse($step1['start_date'], $tz)->startOfWeek(Carbon::MONDAY);
        $end = (clone $start)->endOfWeek(Carbon::SUNDAY);

        // Piano
        $plan = new ContentPlan();
        $plan->tenant_id = $tenantId;
        $plan->created_by = $user->id;
        $plan->name = $step1['name'];
        $plan->start_date = $start->toDateString();
        $plan->end_date = $end->toDateString();
        $plan->status = 'active';
        $plan->settings = [
            'goal' => $step1['goal'],
            'tone' => $step1['tone'],
            'platforms' => $step1['platforms'],
            'formats' => $step1['formats'],
            'posts_per_week' => (int)$step1['posts_per_week'],
            'brand' => $brand,
        ];
        $plan->save();

        // slots
        $count = (int)$step1['posts_per_week'];
        $platforms = $step1['platforms'];
        $formats = $step1['formats'];

        $daysOrder = [0, 2, 4, 6, 1, 3, 5];
        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $dayOffset = $daysOrder[$i % count($daysOrder)];
            $date = $start->copy()->addDays($dayOffset);
            $hour = [10, 13, 18, 21][$i % 4];
            $slots[] = $date->copy()->setTime($hour, 0, 0);
        }

        // crea items base
        $items = [];
        foreach ($slots as $i => $dt) {
            $item = new ContentItem();
            $item->tenant_id = $tenantId;
            $item->content_plan_id = $plan->id;
            $item->created_by = $user->id;

            $item->platform = $platforms[$i % count($platforms)];
            $item->format = $formats[$i % count($formats)];
            $item->scheduled_at = $dt;

            $item->status = 'draft';
            $item->title = 'Bozza #' . ($i + 1) . ' — ' . ucfirst($item->platform);

            $bn = $brand['business_name'];
            $cta = $brand['cta'];

            $item->caption = "Brand: {$bn}\nObiettivo: {$step1['goal']} | Tone: {$step1['tone']}\nCTA: {$cta}\n\n(Placeholder: AI)";
            $item->hashtags = [];
            $item->ai_meta = [
                'wizard' => [
                    'step1' => $step1,
                    'brand' => $brand,
                ],
                'ai' => [
                    'status' => 'pending',
                ],
            ];
            $item->save();
            $items[] = $item;
        }

        // genera contenuti AI (best-effort)
        $this->generateAiForItems($items, $plan, $step1, $brand);

        // pulizia sessione wizard
        session()->forget(['wizard_step1', 'wizard_brand']);

        return redirect()->route('wizard.done')->with('plan_id', $plan->id);
    }

    private function generateAiForItems(array $items, ContentPlan $plan, array $step1, array $brand): void
    {
        // se non hai la chiave, non blocchiamo
        if (!config('openai.api_key')) {
            foreach ($items as $it) {
                $it->ai_meta = array_merge((array)$it->ai_meta, [
                    'ai' => ['status' => 'skipped', 'reason' => 'OPENAI_API_KEY missing'],
                ]);
                $it->save();
            }
            return;
        }

        $openai = app(OpenAIClient::class);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'hook' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 3,
                    'maxItems' => 20,
                ],
                'cta' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['hook', 'title', 'caption', 'hashtags', 'cta', 'notes'],
        ];

        foreach ($items as $item) {
            try {
                $topic = "Piano per {$brand['business_name']} — {$brand['industry']} — servizi: {$brand['services']}";

                $system = "Sei un content strategist senior. "
                    . "Genera contenuti pronti da pubblicare. "
                    . "Lingua: it. "
                    . "Brand: {$brand['business_name']}. "
                    . "Target: {$brand['target']}. "
                    . "Area: " . ($brand['geo'] ?: 'Italia') . ". "
                    . "Obiettivo: {$step1['goal']}. "
                    . "Tono: {$step1['tone']}. "
                    . "Piattaforma: {$item->platform}. "
                    . "Formato: {$item->format}. "
                    . "CTA: {$brand['cta']}. "
                    . "Usa parole chiave se presenti: " . ($brand['keywords'] ?: 'n/a') . ". "
                    . "Evita: " . ($brand['avoid'] ?: 'n/a') . ".";

                $user = "Crea un contenuto per la data/orario {$item->scheduled_at}.\n"
                    . "Tema base: {$topic}\n"
                    . "Richiesta:\n"
                    . "- hook breve e incisivo\n"
                    . "- title breve (max ~60 caratteri)\n"
                    . "- caption adatta a {$item->platform}\n"
                    . "- hashtag pertinenti\n"
                    . "- CTA chiara\n"
                    . "- notes: suggerimento visual (reel/carousel/post) e idea creativa.\n";

                $messages = [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ];

                $out = $openai->generateStructured($messages, $schema, 950);

                $item->title = $out['title'] ?? $item->title;
                $item->caption = trim(($out['hook'] ?? '') . "\n\n" . ($out['caption'] ?? '') . "\n\n" . ($out['cta'] ?? ''));
                $item->hashtags = $out['hashtags'] ?? [];
                $item->ai_meta = array_merge((array)$item->ai_meta, [
                    'ai' => [
                        'status' => 'ok',
                        'notes' => $out['notes'] ?? '',
                        'model' => config('openai.model'),
                    ],
                ]);
                $item->save();
            } catch (\Throwable $e) {
                $item->ai_meta = array_merge((array)$item->ai_meta, [
                    'ai' => [
                        'status' => 'fail',
                        'error' => $e->getMessage(),
                    ],
                ]);
                $item->save();
            }
        }
    }

    public function done(Request $request)
    {
        $planId = session('plan_id');
        return view('wizard.done', compact('planId'));
    }
}
