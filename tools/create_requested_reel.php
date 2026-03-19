<?php

declare(strict_types=1);

use App\Jobs\GenerateAiForContentItem;
use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AssetVariableService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? 'puscastanislav@gmail.com';
$tenantId = 4;
$timezone = config('app.timezone', 'Europe/Rome');
$now = Carbon::now($timezone);

$ffmpegBinary = 'C:\\Program Files\\Wondershare\\Recoverit - Data Recovery (CPC)\\ffmpeg.exe';
$ffprobeBinary = 'C:\\Program Files\\Wondershare\\Recoverit - Data Recovery (CPC)\\ffprobe.exe';
config([
    'generation.ffmpeg_binary' => $ffmpegBinary,
    'generation.ffprobe_binary' => $ffprobeBinary,
]);

$publicDisk = Storage::disk('public');

$silviaFrontPath = 'brand-assets/4/persona-packs/silvia/images/ImCCgBtAZFe6URmZom0ppCnWieGZk6Tk6mRM9MsY.jpg';
$silviaThreeQuarterPath = 'brand-assets/4/persona-packs/silvia/images/iXEdkapjeXd3ncBaLHCqkBmRyXHHT99EzlOgdIoA.jpg';
$silviaProfilePath = 'brand-assets/4/persona-packs/silvia/images/FkIGgoLUCWQOd0AlWUzZowObAg4sTA7EQvsDaDtW.jpg';
$silviaHalfBodyPath = 'brand-assets/4/persona-packs/silvia/images/EtkArqXs5KmaUkFBR55Vasmg3LUitD4Vs3Jg24lw.jpg';
$silviaVideoPath = 'brand-assets/4/persona-packs/silvia/video/cj5QvALI6JWVWo0JQOC5zuUpVl3VifRtx4qoUJJa.mp4';
$porschePath = 'brand-assets/4/images/D1bdK2sdQz88EcdpFimzwQXbCbsdRbTdRv18Z1ya.jpg';

$requiredPaths = [
    $silviaFrontPath,
    $silviaThreeQuarterPath,
    $silviaProfilePath,
    $silviaHalfBodyPath,
    $silviaVideoPath,
    $porschePath,
];

foreach ($requiredPaths as $path) {
    if (!$publicDisk->exists($path)) {
        fwrite(STDERR, "Missing required media path: {$path}\n");
        exit(1);
    }
}

/** @return array{path:string, original_name:string, size:int, mime:string} */
$mediaInfo = static function (string $path) use ($publicDisk): array {
    $absolutePath = $publicDisk->path($path);

    return [
        'path' => $path,
        'original_name' => basename($path),
        'size' => (int) (filesize($absolutePath) ?: 0),
        'mime' => (string) (mime_content_type($absolutePath) ?: 'application/octet-stream'),
    ];
};

/**
 * @param  array<string, mixed>  $defaults
 */
$upsertAsset = static function (int $tenantId, string $path, string $kind, array $defaults = []) use ($mediaInfo): BrandAsset {
    $info = $mediaInfo($path);
    $asset = BrandAsset::query()
        ->where('tenant_id', $tenantId)
        ->where('path', $path)
        ->whereNull('content_plan_id')
        ->first();

    if (!$asset) {
        $asset = new BrandAsset();
        $asset->tenant_id = $tenantId;
        $asset->content_plan_id = null;
        $asset->path = $path;
    }

    $asset->kind = $kind;
    $asset->original_name = (string) ($defaults['original_name'] ?? $info['original_name']);
    $asset->size = (int) ($defaults['size'] ?? $info['size']);
    $asset->mime = (string) ($defaults['mime'] ?? $info['mime']);
    $asset->meta = is_array($defaults['meta'] ?? null) ? $defaults['meta'] : [];
    $asset->save();

    return $asset;
};

$tenant = Tenant::query()->find($tenantId);
if (!$tenant) {
    $tenant = new Tenant();
    $tenant->id = $tenantId;
}
$tenant->name = 'Motorsport Workspace';
$tenant->slug = 'motorsport-workspace';
$tenant->plan = 'internal';
$tenant->is_active = true;
$tenant->limits = [
    'content_items_monthly' => 500,
    'manual_generation' => true,
];
$tenant->save();

TenantProfile::query()->updateOrCreate(
    ['tenant_id' => $tenantId],
    [
        'business_name' => 'Motorsport Workspace',
        'industry' => 'Auto sportive e classiche',
        'services' => 'Presentazione, vendita e valorizzazione di auto sportive e d epoca.',
        'target' => 'Appassionati, collezionisti e clienti premium interessati a vetture iconiche.',
        'cta' => 'Scrivici per dettagli e disponibilita.',
        'default_goal' => 'Awareness',
        'default_tone' => 'premium, credibile, editoriale',
        'default_posts_per_week' => 3,
        'default_platforms' => ['instagram'],
        'default_formats' => ['reel'],
        'completed_at' => $now,
    ]
);

$user = User::query()->firstOrNew(['email' => $email]);
$user->name = $user->name ?: 'Stanislav Pusca';
$user->tenant_id = $tenantId;
$user->role = 'owner';
$user->email_verified_at = $now;
if (!$user->exists) {
    $user->password = Hash::make(Str::random(32));
}
$user->save();

$silviaFront = $upsertAsset($tenantId, $silviaFrontPath, 'image', [
    'meta' => [
        'source' => 'guided_persona_pack',
        'slot' => 'front',
        'variable_kind' => 'person',
        'variable_name' => 'Silvia Bellot',
    ],
]);
$silviaThreeQuarter = $upsertAsset($tenantId, $silviaThreeQuarterPath, 'image', [
    'meta' => [
        'source' => 'guided_persona_pack',
        'slot' => 'three_quarter_left',
        'variable_kind' => 'person',
        'variable_name' => 'Silvia Bellot',
    ],
]);
$silviaProfile = $upsertAsset($tenantId, $silviaProfilePath, 'image', [
    'meta' => [
        'source' => 'guided_persona_pack',
        'slot' => 'profile',
        'variable_kind' => 'person',
        'variable_name' => 'Silvia Bellot',
    ],
]);
$silviaHalfBody = $upsertAsset($tenantId, $silviaHalfBodyPath, 'image', [
    'meta' => [
        'source' => 'guided_persona_pack',
        'slot' => 'half_body',
        'variable_kind' => 'person',
        'variable_name' => 'Silvia Bellot',
    ],
]);
$silviaVideo = $upsertAsset($tenantId, $silviaVideoPath, 'video', [
    'meta' => [
        'source' => 'guided_persona_pack',
        'slot' => 'reference_video',
        'variable_kind' => 'person',
        'variable_name' => 'Silvia Bellot',
    ],
]);
$porscheAsset = $upsertAsset($tenantId, $porschePath, 'image', [
    'meta' => [
        'source' => 'product_anchor',
        'slot' => 'hero_product',
        'variable_kind' => 'product',
        'variable_name' => 'Porsche Bianca',
    ],
]);

$silviaAssetIds = [
    (int) $silviaFront->id,
    (int) $silviaThreeQuarter->id,
    (int) $silviaProfile->id,
    (int) $silviaHalfBody->id,
    (int) $silviaVideo->id,
];

$silviaVariable = AssetVariable::query()->firstOrNew([
    'tenant_id' => $tenantId,
    'slug' => 'silvia-bellot',
]);
$silviaVariable->name = 'Silvia Bellot';
$silviaVariable->kind = 'person';
$silviaVariable->asset_role = 'presenter';
$silviaVariable->description = 'Persona reale del brand, volto e voce della presentazione premium.';
$silviaVariable->asset_ids = $silviaAssetIds;
$silviaVariable->canonical_asset_id = (int) $silviaFront->id;
$silviaVariable->voice_asset_id = null;
$silviaVariable->voice_provider = null;
$silviaVariable->voice_provider_voice_id = null;
$silviaVariable->voice_status = null;
$silviaVariable->identity_mode = 'strict';
$silviaVariable->consistency_threshold = 92;
$silviaVariable->profile = [
    'source_mode' => 'guided_persona_pack',
    'role' => 'Presenter del brand automotive',
    'identity_summary' => 'Donna reale, giovane, presenza elegante e naturale, camicia bianca, taglio premium da showroom.',
    'immutable_traits' => 'Stesso volto reale, stessi lineamenti, stessi capelli castano chiaro, stessa eta apparente, stessa presenza pulita ed elegante.',
    'descriptor' => [
        'summary' => 'Volto reale e credibile del brand, look pulito e professionale.',
        'stable_traits' => 'Lineamenti reali, capelli castano chiaro, pelle naturale, presenza raffinata.',
    ],
    'look_notes' => 'Camicia bianca, styling essenziale, presenza naturale, postura composta.',
    'styling_notes' => 'Taglio editoriale premium, mai artificiale o plastico.',
    'prompt_lock' => [
        'immutable_elements' => 'Stesso volto, stessi capelli, stessa persona reale, stessa presenza elegante.',
        'lock_copy' => 'Silvia deve sembrare reale e coerente in ogni shot.',
    ],
    'allowed_transforms' => [
        'camera angle variation',
        'lighting adaptation',
        'pose variation',
        'walking and gesture variation',
    ],
    'prompt_notes' => 'Usa Silvia come presenter reale. Volto credibile, mani corrette, pelle naturale, sorriso misurato, postura elegante.',
    'usage_notes' => 'Silvia presenta auto premium in reel verticali Instagram con taglio reale e pulito.',
    'shot_count' => 4,
    'shot_summary' => [
        ['slot' => 'front', 'label' => 'Frontale', 'asset_id' => (int) $silviaFront->id, 'path' => (string) $silviaFront->path],
        ['slot' => 'three_quarter_left', 'label' => 'Tre quarti sinistra', 'asset_id' => (int) $silviaThreeQuarter->id, 'path' => (string) $silviaThreeQuarter->path],
        ['slot' => 'profile', 'label' => 'Profilo', 'asset_id' => (int) $silviaProfile->id, 'path' => (string) $silviaProfile->path],
        ['slot' => 'half_body', 'label' => 'Mezzo busto', 'asset_id' => (int) $silviaHalfBody->id, 'path' => (string) $silviaHalfBody->path],
    ],
    'recommended_prompt' => 'Usa Silvia Bellot come presenter reale, premium e credibile. Mantieni identita, volto, capelli e presenza coerenti in ogni shot. Non trasformarla in modello artificiale.',
    'preferred_still_asset_id' => (int) $silviaFront->id,
    'canonical_asset_id' => (int) $silviaFront->id,
    'reference_video_asset_id' => (int) $silviaVideo->id,
    'reference_video_path' => (string) $silviaVideo->path,
    'created_from_brand_center' => true,
];
$silviaVariable->is_active = true;
$silviaVariable->save();

$porscheVariable = AssetVariable::query()->firstOrNew([
    'tenant_id' => $tenantId,
    'slug' => 'porsche-bianca',
]);
$porscheVariable->name = 'Porsche Bianca';
$porscheVariable->kind = 'product';
$porscheVariable->asset_role = 'hero_product';
$porscheVariable->description = 'Porsche 911 Turbo chiara/argento usata come hero product del reel.';
$porscheVariable->asset_ids = [(int) $porscheAsset->id];
$porscheVariable->canonical_asset_id = (int) $porscheAsset->id;
$porscheVariable->voice_asset_id = null;
$porscheVariable->voice_provider = null;
$porscheVariable->voice_provider_voice_id = null;
$porscheVariable->voice_status = null;
$porscheVariable->identity_mode = 'strict';
$porscheVariable->consistency_threshold = 94;
$porscheVariable->profile = [
    'role' => 'Veicolo hero del contenuto',
    'identity_summary' => 'Porsche 911 Turbo chiara, coupe, taglio premium, proporzioni reali.',
    'immutable_traits' => 'Stesso modello Porsche 911 Turbo, stessa tinta chiara/argento, stessa silhouette coupe, stesse proporzioni reali.',
    'descriptor' => [
        'summary' => 'Auto hero premium da mantenere coerente tra gli shot.',
    ],
    'prompt_lock' => [
        'immutable_elements' => 'Porsche 911 Turbo chiara/argento, coupe, proporzioni corrette, look reale.',
    ],
    'allowed_transforms' => [
        'camera angle variation',
        'detail closeups',
        'lighting adaptation',
        'background adaptation',
    ],
    'prompt_notes' => 'La Porsche deve restare reale, premium, pulita e coerente. Nessun drift di modello o colore.',
    'preferred_still_asset_id' => (int) $porscheAsset->id,
    'canonical_asset_id' => (int) $porscheAsset->id,
];
$porscheVariable->is_active = true;
$porscheVariable->save();

$silviaFront->meta = array_merge((array) $silviaFront->meta, [
    'linked_variable_id' => (int) $silviaVariable->id,
    'linked_variable_slug' => 'silvia-bellot',
]);
$silviaFront->save();
$silviaThreeQuarter->meta = array_merge((array) $silviaThreeQuarter->meta, [
    'linked_variable_id' => (int) $silviaVariable->id,
    'linked_variable_slug' => 'silvia-bellot',
]);
$silviaThreeQuarter->save();
$silviaProfile->meta = array_merge((array) $silviaProfile->meta, [
    'linked_variable_id' => (int) $silviaVariable->id,
    'linked_variable_slug' => 'silvia-bellot',
]);
$silviaProfile->save();
$silviaHalfBody->meta = array_merge((array) $silviaHalfBody->meta, [
    'linked_variable_id' => (int) $silviaVariable->id,
    'linked_variable_slug' => 'silvia-bellot',
]);
$silviaHalfBody->save();
$silviaVideo->meta = array_merge((array) $silviaVideo->meta, [
    'linked_variable_id' => (int) $silviaVariable->id,
    'linked_variable_slug' => 'silvia-bellot',
]);
$silviaVideo->save();
$porscheAsset->meta = array_merge((array) $porscheAsset->meta, [
    'linked_variable_id' => (int) $porscheVariable->id,
    'linked_variable_slug' => 'porsche-bianca',
]);
$porscheAsset->save();

/** @var AssetVariableService $assetVariableService */
$assetVariableService = app(AssetVariableService::class);
$catalog = $assetVariableService->catalogForTenant($tenantId);
$catalogById = collect($catalog)->keyBy(fn (array $row) => (int) ($row['id'] ?? 0));
$silviaRow = (array) ($catalogById->get((int) $silviaVariable->id) ?? []);
$porscheRow = (array) ($catalogById->get((int) $porscheVariable->id) ?? []);

$assetVariablesMeta = [
    'catalog' => $catalog,
    'requested_ids' => [(int) $porscheVariable->id, (int) $silviaVariable->id],
    'detected_ids' => [],
    'resolved_ids' => [(int) $porscheVariable->id, (int) $silviaVariable->id],
    'resolved_asset_ids' => array_values(array_unique(array_filter(array_merge(
        (array) ($porscheRow['asset_ids'] ?? []),
        (array) ($silviaRow['asset_ids'] ?? [])
    )))),
    'resolved_asset_paths' => array_values(array_unique(array_filter([
        (string) $porscheAsset->path,
        (string) $silviaFront->path,
        (string) $silviaThreeQuarter->path,
        (string) $silviaProfile->path,
    ]))),
    'resolved' => array_values(array_filter([$porscheRow, $silviaRow])),
    'recognized_terms' => ['Porsche Bianca', 'Silvia Bellot'],
    'selection_mode' => 'manual',
];

$assetIdentity = [
    'slots' => [
        'presenter' => $silviaRow,
        'product' => $porscheRow,
    ],
    'slot_ids' => [(int) $silviaVariable->id, (int) $porscheVariable->id],
    'seasonal_overlay' => '',
    'consistency_mode' => 'strict',
    'locked_elements' => array_values(array_unique(array_filter([
        (string) data_get($silviaRow, 'profile.prompt_lock.immutable_elements', ''),
        (string) data_get($porscheRow, 'profile.prompt_lock.immutable_elements', ''),
    ]))),
    'allowed_changes' => array_values(array_unique(array_filter(array_merge(
        (array) data_get($silviaRow, 'profile.allowed_transforms', []),
        (array) data_get($porscheRow, 'profile.allowed_transforms', [])
    )))),
];

$plan = ContentPlan::query()->firstOrNew([
    'tenant_id' => $tenantId,
    'name' => 'Create Only',
]);
$plan->created_by = (int) $user->id;
$plan->start_date = $now->copy()->startOfDay();
$plan->end_date = $now->copy()->endOfDay();
$plan->status = 'active';
$plan->settings = [
    'goal' => 'Awareness',
    'tone' => 'Premium, reale, credibile',
    'platforms' => ['instagram'],
    'formats' => ['reel'],
];
$plan->strategy = [
    'brand_voice' => [
        'tone' => 'Premium, essenziale, credibile',
    ],
    'brand_references' => [
        'reference_images' => [
            (string) $porscheAsset->path,
            (string) $silviaFront->path,
            (string) $silviaThreeQuarter->path,
        ],
    ],
];
$plan->save();

$brief = 'Crea un reel Instagram verticale con Silvia Bellot che presenta una Porsche bianca/chiara. Silvia deve sembrare una persona reale, stessa identita in tutto il video, look premium e naturale. Il reel deve durare almeno 20 secondi reali: se il provider non arriva a quella durata in una clip sola, usa due segmenti coerenti con tagli puliti e unione naturale. Apri con Silvia accanto alla Porsche, passa a dettagli auto e movimento, poi torna a Silvia che accompagna la presentazione con payoff finale premium.';

$contentItem = new ContentItem();
$contentItem->tenant_id = $tenantId;
$contentItem->content_plan_id = (int) $plan->id;
$contentItem->created_by = (int) $user->id;
$contentItem->platform = 'instagram';
$contentItem->format = 'reel';
$contentItem->scheduled_at = $now;
$contentItem->status = 'draft';
$contentItem->title = 'Silvia Bellot presenta la Porsche bianca';
$contentItem->caption = $brief;
$contentItem->hashtags = [];
$contentItem->assets = [];
$contentItem->source_refs = [
    ['type' => 'asset_variable', 'asset_variable_id' => (int) $silviaVariable->id, 'path' => (string) $silviaFront->path],
    ['type' => 'asset_variable', 'asset_variable_id' => (int) $porscheVariable->id, 'path' => (string) $porscheAsset->path],
];
$contentItem->rubric = 'On Demand';
$contentItem->pillar = 'Richiesta Manuale';
$contentItem->content_angle = 'Reel premium con presenter reale e hero car chiara.';
$contentItem->ai_status = 'queued';
$contentItem->ai_error = null;
$contentItem->ai_meta = [
    'source' => 'manual_single_content',
    'video_provider' => 'openai',
    'image_provider' => 'nanobanana',
    'requested_video_duration_seconds' => 20,
    'tenant_profile' => [
        'business_name' => 'Motorsport Workspace',
        'industry' => 'Auto sportive e classiche',
        'services' => 'Presentazione e valorizzazione di auto premium.',
        'target' => 'Appassionati e clienti premium.',
        'cta' => 'Scrivici per dettagli e disponibilita.',
    ],
    'brand_assets' => [],
    'image_references' => [
        'selection_mode' => 'manual',
        'selected_paths' => [
            (string) $porscheAsset->path,
            (string) $silviaFront->path,
        ],
    ],
    'asset_variables' => $assetVariablesMeta,
    'asset_identity' => $assetIdentity,
    'plan' => [
        'goal' => 'Awareness',
        'tone' => 'Premium, reale, credibile',
        'posts_total' => 1,
        'platforms' => ['instagram'],
        'formats' => ['reel'],
        'date_range' => [$now->toDateString(), $now->toDateString()],
    ],
    'strategy' => $plan->strategy,
    'item_brain' => [
        'rubric' => 'On Demand',
        'pillar' => 'Richiesta Manuale',
        'angle' => 'Silvia Bellot presenta la Porsche bianca in un reel Instagram reale, premium e scorrevole.',
        'objective' => 'Awareness',
        'key_points' => [
            'Silvia reale e credibile',
            'Porsche bianca/chiara come hero product',
            'tagli puliti e senso logico tra i segmenti',
            'durata finale di almeno 20 secondi',
        ],
        'cta' => 'Scrivici per dettagli e disponibilita.',
        'image_direction' => 'Vertical 9:16, live-action photorealism, premium automotive reel, Silvia reale e Porsche coerente.',
        'series_name' => 'contenuto-singolo',
        'series_step' => 1,
        'standalone_rule' => 'Il reel deve funzionare anche da solo.',
        'connection_hint' => 'Create only per account dedicato.',
        'uniqueness_key' => sha1($email . '|' . $brief . '|' . $now->toIso8601String()),
    ],
    'manual_brief' => $brief,
    'image_preference' => [
        'path' => (string) $porscheAsset->path,
        'reason' => 'explicit_reference_selection',
        'confidence' => 1.0,
    ],
    'created_at' => $now->toDateTimeString(),
];
$contentItem->save();

fwrite(STDOUT, "User: {$email} (tenant {$tenantId})\n");
fwrite(STDOUT, "Content item created: {$contentItem->id}\n");
fwrite(STDOUT, "Running AI generation with provider openai and total target 20s...\n");

GenerateAiForContentItem::dispatchSync((int) $contentItem->id);

$contentItem->refresh();
$videoPath = (string) data_get($contentItem->ai_meta, 'video_generation.video_path', '');
$thumbPath = (string) data_get($contentItem->ai_meta, 'video_generation.thumbnail_path', '');
$segments = (array) data_get($contentItem->ai_meta, 'video_generation.segments', []);

fwrite(STDOUT, "AI status: {$contentItem->ai_status}\n");
if ($contentItem->ai_error) {
    fwrite(STDOUT, "AI error: {$contentItem->ai_error}\n");
}
if ($videoPath !== '') {
    fwrite(STDOUT, "Video path: {$videoPath}\n");
}
if ($thumbPath !== '') {
    fwrite(STDOUT, "Thumbnail path: {$thumbPath}\n");
}
if (!empty($segments)) {
    fwrite(STDOUT, 'Segments: ' . count($segments) . "\n");
}

if ($contentItem->ai_status !== 'done') {
    exit(1);
}
