<?php

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Services\AssetVariableService;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\ContentHistoryAnalyzer;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\MemoryBuilderService;
use App\Support\GenerationExecution;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class PlanWizardController extends Controller
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly AssetVariableService $assetVariableService,
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
            'posts_per_week' => max(2, (int) ($profile->default_posts_per_week ?? 5)),
            'platforms' => $profile->default_platforms ?? ['instagram'],
            'formats' => $profile->default_formats ?? ['post'],
        ];

        $step1 = array_merge($defaults, $request->session()->get('plan.step1', []));
        $step1['posts_per_week'] = max(2, (int) ($step1['posts_per_week'] ?? 5));

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
            'posts_per_week' => 'required|integer|min:2|max:31',
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
        if (GenerationExecution::shouldRunSync() && (($counts['queued'] + $counts['pending']) > 0)) {
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

        if ($this->useFixedHostupPosts()) {
            try {
                $plan = ContentPlan::create([
                    'tenant_id' => $user->tenant_id,
                    'created_by' => $user->id,
                    'name' => $step1['name'],
                    'start_date' => Carbon::parse($step1['start_date'])->toDateString(),
                    'end_date' => Carbon::parse($step1['end_date'])->toDateString(),
                    'status' => 'draft',
                    'settings' => [
                        'goal' => $step1['goal'] ?? null,
                        'tone' => $step1['tone'] ?? null,
                        'posts_total' => 6,
                        'platforms' => ['instagram', 'facebook'],
                        'formats' => ['post', 'reel'],
                        'mode' => 'fixed_hostup_posts',
                    ],
                    'strategy' => [],
                ]);

                $created = $this->createFixedHostupPosts($plan, (int) $user->tenant_id, (int) $user->id);
                $request->session()->put('plan.plan_id', $plan->id);

                return redirect()->route('wizard.done')
                    ->with('status', "Piano demo creato (ID: {$plan->id}) con {$created} contenuti fissi da contenuti-per-i-post.");
            } catch (\Throwable $e) {
                return redirect()->route('wizard.done')->with('status', 'Errore creazione piano fisso: ' . $e->getMessage());
            }
        }

        $start = Carbon::parse($step1['start_date'])->startOfDay();
        $end = Carbon::parse($step1['end_date'])->endOfDay();
        $postsTotal = max(2, (int) ($step1['posts_per_week'] ?? 5));
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
        $assetVariables = $this->assetVariableService->catalogForTenant((int) $user->tenant_id);

        $strictAssetMode = (bool) config('generation.strict_asset_mode', true);
        $hasBrandImages = collect($assets)
            ->contains(fn ($asset) => is_array($asset) && (($asset['kind'] ?? null) === 'image') && !empty($asset['path']));
        if ($strictAssetMode && !$hasBrandImages) {
            return redirect()->route('profile.brand')
                ->with('status', 'Strict mode attivo: carica almeno 1 immagine in Brand Assets prima di generare un piano editoriale.');
        }

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
        $strategy = $this->editorialStrategyService->toRuntimeContext(
            $strategyModel,
            $this->buildBrandReferences($profileData, $assets, $assetVariables)
        );

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
                            'asset_variables' => $assetVariables,
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
                            'asset_variables' => $assetVariables,
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
                        options: ['platforms' => $platforms, 'formats' => $formats, 'memory' => $memory]
                    );
                }

                if (!empty($itemsToGenerate)) {
                    $this->contentGenerator->generateForPlan($plan, $itemsToGenerate, [
                        'user_id' => (int) $user->id,
                        'profile_data' => $profileData,
                        'strategy' => $strategy,
                        'memory' => $memory,
                        'assets' => $assets,
                        'asset_variables' => $assetVariables,
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
                        'asset_variables' => $assetVariables,
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
                    options: ['platforms' => $platforms, 'formats' => $formats, 'memory' => $memory]
                );

                $this->contentGenerator->generateForPlan($newPlan, $itemsToGenerate, [
                    'user_id' => (int) $user->id,
                    'profile_data' => $profileData,
                    'strategy' => $strategy,
                    'memory' => $memory,
                    'assets' => $assets,
                    'asset_variables' => $assetVariables,
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
                GenerationExecution::dispatchContentItem((int) $itemId);
            }
        } catch (\Throwable) {
            // best effort
        }

        return redirect()->route('wizard.done')
            ->with('status', "Piano aggiornato (ID: {$plan->id}) con logica editoriale avanzata e anti-duplicati.");
    }

    private function buildBrandReferences(array $profileData, array $assets, array $assetVariables = []): array
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
            'asset_variables' => array_values($assetVariables),
        ];
    }

    private function useFixedHostupPosts(): bool
    {
        return (bool) config('app.fixed_hostup_posts', false);
    }

    private function createFixedHostupPosts(ContentPlan $plan, int $tenantId, int $userId): int
    {
        $media = $this->importFixedMediaFromFolder($tenantId);
        $images = $media['images'];
        $video = $media['video'];

        if (count($images) < 5) {
            throw new \RuntimeException('Servono 5 immagini nella cartella contenuti-per-i-post.');
        }
        if (!$video) {
            throw new \RuntimeException('Serve 1 video nella cartella contenuti-per-i-post.');
        }

        ContentItem::query()->where('content_plan_id', $plan->id)->delete();

        $base = Carbon::parse($plan->start_date ?: now())->startOfDay()->setTime(10, 0, 0);
        $themes = [
            [
                'format' => 'post',
                'title' => 'Gestione affitti brevi piu semplice',
                'caption' => 'Gestire affitti brevi in modo efficace non significa fare tutto a mano, ma avere un flusso operativo chiaro. Quando prenotazioni, disponibilita e attivita quotidiane sono organizzate, riduci errori, migliori la qualita del servizio e lavori con maggiore continuita anche nei periodi di alta richiesta.',
                'cta' => 'Vuoi vedere un flusso operativo piu semplice? Scrivici per una demo.',
                'hashtags' => ['#Hostup', '#AffittiBrevi', '#GestioneImmobili', '#PropertyManagement', '#Hospitality', '#ShortTermRental', '#Workflow', '#Produttivita'],
                'image' => $images[0],
                'assets' => [['type' => 'brand_image', 'path' => $images[0]]],
            ],
            [
                'format' => 'post',
                'title' => 'Integrazione tra applicazioni',
                'caption' => 'L integrazione tra applicazioni e la base di una gestione moderna: meno copia e incolla, meno passaggi manuali, meno errori. Quando i sistemi dialogano tra loro, il team risparmia tempo e puo concentrarsi su analisi, ottimizzazione e crescita.',
                'cta' => 'Ti mostriamo come integrare i tool in modo concreto.',
                'hashtags' => ['#Hostup', '#Integrazione', '#Automazione', '#Workflow', '#DigitalOperations', '#TechForHospitality', '#Efficienza', '#SistemiIntegrati'],
                'image' => $images[1],
                'assets' => [['type' => 'brand_image', 'path' => $images[1]]],
            ],
            [
                'format' => 'post',
                'title' => 'Prezzi dinamici con piu controllo',
                'caption' => 'Aggiornare i prezzi in modo dinamico permette di adattarsi alla domanda reale e difendere i margini. Con regole chiare e dati aggiornati, puoi migliorare occupazione e redditivita senza rincorrere continue modifiche manuali.',
                'cta' => 'Parliamo della tua strategia prezzi attuale.',
                'hashtags' => ['#Hostup', '#Pricing', '#RevenueManagement', '#AffittiBrevi', '#Crescita', '#Occupazione', '#ADR', '#RevPAR'],
                'image' => $images[2],
                'assets' => [['type' => 'brand_image', 'path' => $images[2]]],
            ],
            [
                'format' => 'post',
                'title' => 'KPI chiari per decisioni migliori',
                'caption' => 'I KPI non servono solo a fare report: servono a prendere decisioni migliori. Monitorando gli indicatori giusti puoi capire subito cosa sta funzionando, dove intervenire e come migliorare performance operative e risultati economici.',
                'cta' => 'Ti indichiamo i KPI prioritari per partire.',
                'hashtags' => ['#Hostup', '#KPI', '#DataDriven', '#Performance', '#ShortTermRental', '#Analytics', '#BusinessIntelligence', '#DecisionMaking'],
                'image' => $images[3],
                'assets' => [['type' => 'brand_image', 'path' => $images[3]]],
            ],
            [
                'format' => 'post',
                'title' => 'Risparmia tempo con HostUp',
                'caption' => 'Con HostUp automatizzi le attivita piu ripetitive della gestione affitti brevi e recuperi tempo prezioso per concentrarti sulle priorita strategiche. Dalla sincronizzazione dei calendari al monitoraggio delle prenotazioni, il flusso operativo diventa piu veloce, pulito e affidabile.',
                'cta' => 'Vuoi capire dove puoi risparmiare piu tempo nel tuo flusso attuale? Scrivici.',
                'hashtags' => ['#HostUp', '#RisparmioTempo', '#AffittiBrevi', '#PropertyManagement', '#Automazione', '#SmartWorking', '#HospitalityTech', '#Workflow'],
                'image' => $images[4],
                'assets' => [['type' => 'brand_image', 'path' => $images[4]]],
            ],
            [
                'format' => 'reel',
                'title' => 'Risparmia tempo ogni settimana',
                'caption' => 'Risparmiare tempo non significa fare meno, significa togliere attrito operativo. Automatizzare attivita ripetitive permette al team di concentrarsi sulle decisioni che portano valore: servizio, strategia e crescita.',
                'cta' => 'Contattaci e individuiamo insieme le attivita da automatizzare.',
                'hashtags' => ['#Hostup', '#RisparmioTempo', '#ReelItalia', '#Automazione', '#Productivity', '#TimeSaving', '#TeamEfficiency', '#SmartWork'],
                'image' => null,
                'assets' => [
                    ['type' => 'brand_video', 'path' => $video],
                ],
            ],
        ];

        $index = 0;

        foreach ($themes as $theme) {
            $meta = [
                'demo_locked' => true,
                'source' => 'contenuti-per-i-post',
            ];

            if ($theme['format'] === 'reel') {
                $meta['video_reference'] = ['path' => $video, 'kind' => 'brand_video'];
            }

            $formattedCaption = trim($theme['caption']) . "\n\n" . implode(' ', $theme['hashtags']);

            ContentItem::query()->create([
                'tenant_id' => $tenantId,
                'content_plan_id' => $plan->id,
                'created_by' => $userId,
                'platform' => 'instagram,facebook',
                'format' => $theme['format'],
                'scheduled_at' => $base->copy()->addDays($index),
                'status' => 'draft',
                'title' => $theme['title'],
                'caption' => null,
                'hashtags' => [],
                'assets' => $theme['assets'],
                'ai_status' => 'done',
                'ai_caption' => $formattedCaption,
                'ai_hashtags' => $theme['hashtags'],
                'ai_cta' => $theme['cta'],
                'ai_image_prompt' => 'Preset fisso Hostup',
                'ai_image_path' => $theme['image'],
                'ai_error' => null,
                'ai_generated_at' => now(),
                'ai_meta' => $meta,
            ]);

            $index++;
        }

        return $index;
    }

    private function importFixedMediaFromFolder(int $tenantId): array
    {
        $sourceDir = base_path('contenuti-per-i-post');
        if (!is_dir($sourceDir)) {
            throw new \RuntimeException('Cartella contenuti-per-i-post non trovata.');
        }

        $files = collect(glob($sourceDir . DIRECTORY_SEPARATOR . '*'))
            ->filter(fn ($path) => is_file($path))
            ->values();

        $imageFiles = $files
            ->filter(function ($path) {
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
            })
            ->sortBy(fn ($path) => filemtime($path) ?: 0)
            ->values();

        $videoFiles = $files
            ->filter(function ($path) {
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                return in_array($ext, ['mp4', 'mov', 'avi', 'webm', 'm4v'], true);
            })
            ->sort()
            ->values();

        if ($imageFiles->count() < 5 || $videoFiles->count() < 1) {
            throw new \RuntimeException('La cartella deve contenere 5 immagini e 1 video.');
        }

        BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->whereIn('kind', ['image', 'video'])
            ->delete();

        $public = Storage::disk('public');
        $baseDir = 'brand-assets/' . $tenantId . '/imported';

        $images = [];
        for ($i = 0; $i < 5; $i++) {
            $abs = (string) $imageFiles[$i];
            $name = basename($abs);
            $path = $baseDir . '/' . $name;
            $public->put($path, file_get_contents($abs));

            BrandAsset::create([
                'tenant_id' => $tenantId,
                'content_plan_id' => null,
                'kind' => 'image',
                'path' => $path,
                'original_name' => $name,
                'size' => filesize($abs) ?: null,
                'mime' => mime_content_type($abs) ?: null,
            ]);

            $images[] = $path;
        }

        $videoAbs = (string) $videoFiles->last();
        $videoName = basename($videoAbs);
        $videoPath = $baseDir . '/' . $videoName;
        $public->put($videoPath, file_get_contents($videoAbs));

        BrandAsset::create([
            'tenant_id' => $tenantId,
            'content_plan_id' => null,
            'kind' => 'video',
            'path' => $videoPath,
            'original_name' => $videoName,
            'size' => filesize($videoAbs) ?: null,
            'mime' => mime_content_type($videoAbs) ?: null,
        ]);

        return [
            'images' => $images,
            'video' => $videoPath,
        ];
    }
}
