<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\MemoryBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ContentItemController extends Controller
{
    public function __construct(
        private readonly MemoryBuilderService $memoryBuilder,
        private readonly EditorialStrategyService $editorialStrategyService
    ) {
    }

    /**
     * LISTA "POSTS" (la tua pagina attuale) => resources/views/posts/index.blade.php
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $baseQuery = ContentItem::query()->where('tenant_id', $user->tenant_id);

        $items = (clone $baseQuery)
            ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $statusCounts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'scheduled' => (clone $baseQuery)->whereNotNull('scheduled_at')->count(),
            'published' => (clone $baseQuery)
                ->where(function ($q) {
                    $q->where('status', 'published')->orWhereNotNull('published_at');
                })
                ->count(),
            'ai_done' => (clone $baseQuery)->where('ai_status', 'done')->count(),
            'ai_queued' => (clone $baseQuery)->whereIn('ai_status', ['queued', 'pending'])->count(),
            'ai_error' => (clone $baseQuery)->where('ai_status', 'error')->count(),
            'status_counts' => [
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'review' => (int) ($statusCounts['review'] ?? 0),
                'approved' => (int) ($statusCounts['approved'] ?? 0),
                'scheduled' => (int) ($statusCounts['scheduled'] ?? 0),
                'published' => (int) ($statusCounts['published'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
            ],
        ];

        $tz = config('app.timezone', 'Europe/Rome');
        $todayStart = now($tz)->startOfDay();
        $todayEnd = now($tz)->endOfDay();

        $todayItems = (clone $baseQuery)
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->orderBy('scheduled_at')
            ->get();

        $todayPending = $todayItems->where('status', '!=', 'published')->count();

        $nextItem = (clone $baseQuery)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now($tz))
            ->orderBy('scheduled_at')
            ->first();

        return view('posts.index', compact('items', 'stats', 'todayItems', 'todayPending', 'nextItem'));
    }

    /**
     * LISTA "CONTENT ITEMS" (nuova galleria con immagini) => resources/views/content-items/index.blade.php
     */
    public function gallery(Request $request)
    {
        $user = $request->user();

        $q = ContentItem::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        // filtri opzionali (se li aggiungi in futuro)
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('platform')) {
            $q->where('platform', $request->string('platform')->toString());
        }

        $items = $q->paginate(24)->withQueryString();

        return view('content-items.index', compact('items'));
    }

    /**
     * DETTAGLIO "CONTENT ITEM" (immagine grande) => resources/views/content-items/show.blade.php
     */
    public function show(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        return view('content-items.show', [
            'item' => $contentItem,
        ]);
    }

    public function create(Request $request)
    {
        $profile = TenantProfile::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->first();

        $referenceImages = $this->loadBrandReferenceImages((int) $request->user()->tenant_id);

        return view('posts.create', compact('profile', 'referenceImages'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $data = $request->validate([
            'platforms' => 'nullable|array|min:1',
            'platforms.*' => 'string|max:50',
            'platform' => 'nullable|string|max:255',
            'format' => 'required|string|max:50',
            'scheduled_at' => 'required|date',
            'generation_brief' => 'nullable|string|max:3000',
            'goal_hint' => 'nullable|string|max:180',
            'title' => 'nullable|string|max:120',
            'caption' => 'nullable|string|max:3000',
            'status' => 'nullable|string|max:30',
            'reference_asset_ids' => 'nullable|array',
            'reference_asset_ids.*' => 'integer',
        ]);

        $platforms = $this->extractPlatforms($request, $data);
        $platformValue = implode(',', $platforms);

        $brief = trim((string) (
            $data['generation_brief']
            ?? $data['caption']
            ?? $data['title']
            ?? ''
        ));

        if ($brief === '') {
            return back()
                ->withErrors(['generation_brief' => 'Inserisci una descrizione di cosa vuoi che l AI generi.'])
                ->withInput();
        }

        $tz = config('app.timezone', 'Europe/Rome');
        $scheduledAt = Carbon::parse((string) $data['scheduled_at'], $tz);

        $status = (string) ($data['status'] ?? '');
        if ($status === '') {
            $status = 'scheduled';
        }

        $profile = TenantProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();

        $profileData = $this->buildProfileData($profile);
        $assets = $this->loadBrandAssets($tenantId);
        $explicitImageReferences = $this->resolveExplicitImageReferences(
            $brief,
            $assets,
            (array) ($data['reference_asset_ids'] ?? [])
        );
        $memory = $this->memoryBuilder->buildForTenant($tenantId, 40);

        $strategyModel = $this->editorialStrategyService->refreshForTenant($tenantId, $profile);
        $strategy = [
            'brand_voice' => $strategyModel->brand_voice ?? [],
            'pillars' => $strategyModel->pillars ?? [],
            'rubrics' => $strategyModel->rubrics ?? [],
            'cta_rules' => $strategyModel->cta_rules ?? [],
            'constraints' => $strategyModel->constraints ?? [],
            'brand_references' => $this->buildBrandReferences($profileData, $assets),
        ];

        $plan = $this->resolvePlanForSingleItem($tenantId, (int) $user->id, $scheduledAt, $strategy);

        $goalHint = trim((string) ($data['goal_hint'] ?? ''));
        $format = (string) $data['format'];
        $internalTitle = Str::limit($brief, 110, '');
        $imagePreference = $explicitImageReferences['primary_preference'] ?? $this->selectPreferredBrandImage($brief, $assets);
        $uniquenessSeed = implode('|', [
            $tenantId,
            $platformValue,
            $format,
            $scheduledAt->format('Y-m-d H:i'),
            $brief,
            microtime(true),
        ]);

        $item = new ContentItem();
        $item->tenant_id = $tenantId;
        $item->content_plan_id = (int) $plan->id;
        $item->created_by = (int) $user->id;

        $item->platform = $platformValue;
        $item->format = $format;
        $item->status = $status;
        $item->title = $internalTitle;
        $item->caption = $brief;
        $item->scheduled_at = $scheduledAt;

        $item->rubric = 'On Demand';
        $item->pillar = 'Richiesta Manuale';
        $item->content_angle = Str::limit($brief, 180, '');
        $item->hashtags = [];
        $item->assets = [];
        $item->source_refs = $this->buildSourceRefsFromExplicitImageReferences($explicitImageReferences);
        $item->ai_status = 'queued';
        $item->ai_error = null;
        $item->ai_meta = [
            'source' => 'manual_single_content',
            'tenant_profile' => $profileData,
            'brand_assets' => $assets,
            'image_references' => $explicitImageReferences,
            'plan' => [
                'goal' => $goalHint !== '' ? $goalHint : data_get($plan->settings, 'goal'),
                'tone' => data_get($plan->settings, 'tone', $profile?->default_tone),
                'posts_total' => 1,
                'platforms' => $platforms,
                'formats' => [$format],
                'date_range' => [$scheduledAt->toDateString(), $scheduledAt->toDateString()],
            ],
            'memory_summary' => $memory,
            'strategy' => $strategy,
            'item_brain' => [
                'rubric' => 'On Demand',
                'pillar' => 'Richiesta Manuale',
                'angle' => Str::limit($brief, 180, ''),
                'objective' => $goalHint !== '' ? $goalHint : 'Awareness',
                'key_points' => [$brief],
                'cta' => (string) ($profile?->cta ?: 'Scrivici per maggiori informazioni.'),
                'image_direction' => 'Visual coerente con il brand e con questo brief: ' . Str::limit($brief, 220, ''),
                'series_name' => 'contenuto-singolo',
                'series_step' => 1,
                'standalone_rule' => 'Il contenuto deve essere completo anche se letto singolarmente.',
                'connection_hint' => 'Contenuto singolo richiesto manualmente.',
                'uniqueness_key' => sha1($uniquenessSeed),
            ],
            'manual_brief' => $brief,
            'image_preference' => $imagePreference,
            'created_at' => now()->toDateTimeString(),
        ];

        $item->save();

        try {
            if (app()->environment('local')) {
                GenerateAiForContentItem::dispatchSync((int) $item->id);
            } else {
                GenerateAiForContentItem::dispatch((int) $item->id);
            }
        } catch (\Throwable $e) {
            $item->ai_status = 'error';
            $item->ai_error = 'QUEUE: ' . $e->getMessage();
            $item->save();

            return redirect()
                ->route('posts.edit', $item)
                ->with('status', 'Contenuto creato, ma la generazione AI non e partita: ' . $e->getMessage());
        }

        return redirect()
            ->route('posts.edit', $item)
            ->with('status', app()->environment('local')
                ? 'Contenuto creato e generato con AI.'
                : 'Contenuto creato e messo in coda AI.');
    }

    public function edit(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        return view('posts.edit', compact('contentItem'));
    }

    public function update(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'format' => 'required|string|max:50',
            'scheduled_at' => 'nullable|date',
            'title' => 'nullable|string|max:120',
            'ai_caption' => 'nullable|string',
            'ai_image_prompt' => 'nullable|string',
            'status' => 'nullable|string|max:30',
        ]);

        $contentItem->platform = $data['platform'];
        $contentItem->format = $data['format'];
        $contentItem->status = $data['status'] ?? $contentItem->status;
        $contentItem->title = $data['title'] ?? null;
        $contentItem->ai_caption = $data['ai_caption'] ?? null;
        $contentItem->ai_image_prompt = $data['ai_image_prompt'] ?? null;
        $contentItem->scheduled_at = !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;

        $contentItem->save();

        return redirect()->route('posts.index')->with('status', 'Contenuto aggiornato.');
    }

    public function destroy(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        $contentItem->delete();

        return redirect()->route('posts.index')->with('status', 'Contenuto eliminato.');
    }

    private function authorizeTenant(Request $request, ContentItem $item): void
    {
        if ((int) $item->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }

    private function extractPlatforms(Request $request, array $data): array
    {
        $platforms = $data['platforms'] ?? null;
        if (is_array($platforms) && !empty($platforms)) {
            return array_values(array_unique(array_map(
                fn ($v) => Str::lower(trim((string) $v)),
                array_filter($platforms, fn ($v) => trim((string) $v) !== '')
            )));
        }

        $raw = trim((string) ($data['platform'] ?? $request->input('platform', '')));
        if ($raw !== '') {
            $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
            $parts = array_values(array_unique(array_map(
                fn ($v) => Str::lower(trim((string) $v)),
                array_filter($parts, fn ($v) => trim((string) $v) !== '')
            )));
            if (!empty($parts)) {
                return $parts;
            }
        }

        return ['instagram'];
    }

    private function buildProfileData(?TenantProfile $profile): array
    {
        return [
            'business_name' => $profile?->business_name,
            'industry' => $profile?->industry,
            'website' => $profile?->website,
            'services' => $profile?->services,
            'target' => $profile?->target,
            'cta' => $profile?->cta,
            'notes' => $profile?->notes,
            'vision' => $profile?->vision,
            'values' => $profile?->values,
            'business_hours' => $profile?->business_hours,
            'seasonal_offers' => $profile?->seasonal_offers,
            'brand_palette' => $profile?->brand_palette,
        ];
    }

    private function loadBrandAssets(int $tenantId): array
    {
        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->limit(48)
            ->get()
            ->map(fn ($asset) => [
                'id' => (int) $asset->id,
                'kind' => (string) $asset->kind,
                'path' => (string) $asset->path,
                'original_name' => (string) ($asset->original_name ?? ''),
                'mime' => (string) ($asset->mime ?? ''),
            ])
            ->values()
            ->all();
    }

    private function loadBrandReferenceImages(int $tenantId): array
    {
        $rows = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'image')
            ->latest('id')
            ->limit(48)
            ->get(['id', 'path', 'original_name', 'mime']);

        $out = [];
        $refNumber = 1;
        foreach ($rows as $row) {
            $path = trim((string) ($row->path ?? ''));
            if ($path === '') {
                continue;
            }

            $out[] = [
                'id' => (int) $row->id,
                'path' => $path,
                'original_name' => (string) ($row->original_name ?? ''),
                'mime' => (string) ($row->mime ?? ''),
                'ref_number' => $refNumber,
            ];
            $refNumber++;
        }

        return $out;
    }

    private function resolveExplicitImageReferences(string $brief, array $assets, array $requestedAssetIds): array
    {
        $images = [];
        $imagesById = [];
        $imagesByNumber = [];
        $number = 1;

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['kind'] ?? null) !== 'image') {
                continue;
            }

            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $assetId = isset($asset['id']) ? (int) $asset['id'] : 0;
            $row = [
                'id' => $assetId > 0 ? $assetId : null,
                'path' => $path,
                'original_name' => (string) ($asset['original_name'] ?? ''),
                'mime' => (string) ($asset['mime'] ?? ''),
                'ref_number' => $number,
            ];

            $images[] = $row;
            $imagesByNumber[$number] = $row;
            if ($assetId > 0) {
                $imagesById[$assetId] = $row;
            }
            $number++;
        }

        $selected = [];
        $selectionSources = [];
        $numbersFromBrief = $this->extractReferenceNumbersFromBrief($brief, count($images));
        if (!empty($numbersFromBrief)) {
            foreach ($numbersFromBrief as $n) {
                if (!isset($imagesByNumber[$n])) {
                    continue;
                }
                $row = $imagesByNumber[$n];
                $selected[(string) $row['path']] = $row;
            }
            $selectionSources[] = 'brief_numbers';
        } else {
            $ids = array_values(array_unique(array_map(
                fn ($v) => (int) $v,
                array_filter($requestedAssetIds, fn ($v) => (int) $v > 0)
            )));
            foreach ($ids as $id) {
                if (!isset($imagesById[$id])) {
                    continue;
                }
                $row = $imagesById[$id];
                $selected[(string) $row['path']] = $row;
            }
            if (!empty($ids)) {
                $selectionSources[] = 'checkbox';
            }
        }

        $selectedAssets = array_values($selected);
        $selectedIds = [];
        $selectedPaths = [];
        $selectedNumbers = [];
        foreach ($selectedAssets as $row) {
            $assetId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($assetId > 0) {
                $selectedIds[] = $assetId;
            }
            $selectedPaths[] = (string) ($row['path'] ?? '');
            $selectedNumbers[] = (int) ($row['ref_number'] ?? 0);
        }

        $primaryPreference = null;
        if (!empty($selectedPaths)) {
            $primaryPreference = [
                'path' => (string) $selectedPaths[0],
                'reason' => 'explicit_reference_selection',
                'confidence' => 1.0,
            ];
        }

        return [
            'selection_mode' => empty($selectionSources) ? 'none' : implode('+', array_values(array_unique($selectionSources))),
            'selected_ids' => array_values(array_unique(array_filter($selectedIds, fn ($v) => $v > 0))),
            'selected_paths' => array_values(array_unique(array_filter($selectedPaths, fn ($v) => trim((string) $v) !== ''))),
            'selected_numbers' => array_values(array_unique(array_filter($selectedNumbers, fn ($v) => (int) $v > 0))),
            'selected_assets' => $selectedAssets,
            'numbers_detected_in_brief' => $numbersFromBrief,
            'available_total' => count($images),
            'numbering' => array_values($images),
            'primary_preference' => $primaryPreference,
        ];
    }

    private function buildSourceRefsFromExplicitImageReferences(array $refs): array
    {
        $selected = (array) ($refs['selected_assets'] ?? []);
        $out = [];

        foreach ($selected as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = trim((string) ($row['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $out[] = [
                'type' => 'brand_image_reference',
                'asset_id' => isset($row['id']) ? (int) $row['id'] : null,
                'path' => $path,
                'ref_number' => isset($row['ref_number']) ? (int) $row['ref_number'] : null,
                'name' => (string) ($row['original_name'] ?? ''),
            ];

            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private function extractReferenceNumbersFromBrief(string $brief, int $maxNumber): array
    {
        if ($maxNumber < 1) {
            return [];
        }

        $numbers = [];
        $push = function (string|int $value) use (&$numbers, $maxNumber): void {
            $n = (int) $value;
            if ($n < 1 || $n > $maxNumber) {
                return;
            }
            $numbers[] = $n;
        };

        if (preg_match_all('/#\s*(\d{1,3})/u', $brief, $mHash)) {
            foreach ((array) ($mHash[1] ?? []) as $raw) {
                $push((string) $raw);
            }
        }

        if (preg_match_all('/\b(?:n|num|numero)\s*\.?\s*(\d{1,3})\b/iu', $brief, $mNum)) {
            foreach ((array) ($mNum[1] ?? []) as $raw) {
                $push((string) $raw);
            }
        }

        if (
            preg_match_all(
                '/\b(?:foto|fotos|immagine|immagini|image|images|asset|assets)\s*(?:n(?:um(?:ero)?)?\.?\s*)?((?:\d{1,3}\s*(?:,|;|\/|\\\\|\||e|ed|-)?\s*){1,8})/iu',
                $brief,
                $mGroups
            )
        ) {
            foreach ((array) ($mGroups[1] ?? []) as $chunk) {
                if (!is_string($chunk) || trim($chunk) === '') {
                    continue;
                }

                if (preg_match_all('/\d{1,3}/', $chunk, $mInline)) {
                    foreach ((array) ($mInline[0] ?? []) as $raw) {
                        $push((string) $raw);
                    }
                }
            }
        }

        return array_values(array_unique($numbers));
    }

    private function buildBrandReferences(array $profileData, array $assets): array
    {
        $logo = null;
        $images = [];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['kind'] ?? null) === 'logo' && $logo === null && !empty($asset['path'])) {
                $logo = (string) $asset['path'];
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

    private function resolvePlanForSingleItem(
        int $tenantId,
        int $userId,
        Carbon $scheduledAt,
        array $strategy
    ): ContentPlan {
        $date = $scheduledAt->toDateString();

        $matchingPlan = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->latest('id')
            ->first();

        if ($matchingPlan) {
            return $matchingPlan;
        }

        $latestPlan = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();

        if ($latestPlan) {
            return $latestPlan;
        }

        return ContentPlan::query()->create([
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'name' => 'Piano singolo ' . $scheduledAt->format('d/m'),
            'start_date' => $date,
            'end_date' => $date,
            'status' => 'draft',
            'settings' => [
                'goal' => 'Contenuto singolo on demand',
                'tone' => 'professionale',
                'posts_total' => 1,
                'platforms' => ['instagram'],
                'formats' => ['post'],
                'mode' => 'single_manual',
            ],
            'strategy' => $strategy,
        ]);
    }

    private function selectPreferredBrandImage(string $brief, array $assets): ?array
    {
        $images = collect($assets)
            ->filter(fn ($a) => is_array($a) && (($a['kind'] ?? null) === 'image') && !empty($a['path']))
            ->values();

        if ($images->isEmpty()) {
            return null;
        }

        $normalizedBrief = $this->normalizeSimpleText($brief);
        if ($normalizedBrief === '') {
            return null;
        }

        if (
            str_contains($normalizedBrief, 'ultima immagine')
            || str_contains($normalizedBrief, 'ultima foto')
            || str_contains($normalizedBrief, 'latest image')
            || str_contains($normalizedBrief, 'last image')
        ) {
            $latest = $images->first();
            return [
                'path' => (string) ($latest['path'] ?? ''),
                'reason' => 'manual_latest_image_hint',
                'confidence' => 1.0,
            ];
        }

        $tokens = $this->extractMeaningfulTokens($normalizedBrief);
        if (empty($tokens)) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($images as $img) {
            $name = (string) ($img['original_name'] ?? '');
            $path = (string) ($img['path'] ?? '');
            $haystack = $this->normalizeSimpleText($name . ' ' . basename($path));

            if ($haystack === '') {
                continue;
            }

            $score = 0;
            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $img;
            }
        }

        if ($best && $bestScore >= 2) {
            return [
                'path' => (string) ($best['path'] ?? ''),
                'reason' => 'manual_brief_keyword_match',
                'confidence' => min(0.95, 0.45 + ($bestScore * 0.08)),
            ];
        }

        return null;
    }

    private function normalizeSimpleText(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    private function extractMeaningfulTokens(string $normalized): array
    {
        $stop = [
            'con', 'senza', 'della', 'delle', 'degli', 'dello', 'dell', 'dalla', 'dalle', 'dallo',
            'dove', 'come', 'questa', 'questo', 'quello', 'quella', 'immagine', 'foto', 'post',
            'social', 'contenuto', 'crea', 'creami', 'genera', 'genera', 'ultim', 'ultima', 'latest',
            'image', 'logo', 'dietro', 'sopra', 'sotto', 'solo', 'anche', 'molto', 'poco', 'voglio',
        ];
        $stopLookup = array_fill_keys($stop, true);

        $parts = preg_split('/\s+/', $normalized) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (isset($stopLookup[$p])) {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }
}
