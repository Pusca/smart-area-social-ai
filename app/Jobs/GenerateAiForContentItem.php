<?php

namespace App\Jobs;

use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateAiForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $item = ContentItem::query()->with('plan')->findOrFail($this->contentItemId);

        $item->ai_status = 'pending';
        $item->ai_error = null;
        $item->save();

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $liveBrandAssets = $this->loadBrandAssetsFromDb((int) $item->tenant_id);
        $meta['brand_assets'] = $this->mergeBrandAssets((array) data_get($meta, 'brand_assets', []), $liveBrandAssets);
        $strategy = data_get($meta, 'strategy', $item->plan?->strategy ?? []);
        $itemBrain = data_get($meta, 'item_brain', []);
        $tenantProfile = data_get($meta, 'tenant_profile', data_get($meta, 'brand', []));
        $memorySummary = data_get($meta, 'memory_summary', []);

        $recentCaptions = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_caption')
            ->whereIn('ai_status', ['done', 'pending'])
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('ai_caption')
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        $planContext = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get(['title', 'ai_caption']);

        $planTitles = $planContext
            ->pluck('title')
            ->filter()
            ->map(fn ($title) => Str::limit((string) $title, 120, ''))
            ->values()
            ->all();

        $planCaptions = $planContext
            ->pluck('ai_caption')
            ->filter()
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        try {
            $context = [
                'brand' => $tenantProfile,
                'plan' => data_get($meta, 'plan', []),
                'strategy' => $strategy,
                'item_brain' => $itemBrain,
                'memory_summary' => $memorySummary,
                'repetition_rules' => [
                    'avoid_list' => array_values(array_unique(array_filter(array_merge(
                        (array) data_get($itemBrain, 'avoid_list', []),
                        (array) data_get($strategy, 'repetition_guard.avoid_terms', []),
                        (array) data_get($memorySummary, 'avoid_repetition', [])
                    )))),
                    'recent_captions' => $recentCaptions,
                    'plan_titles' => $planTitles,
                    'plan_captions' => $planCaptions,
                ],
                'item' => [
                    'platform' => $item->platform,
                    'format' => $item->format,
                    'title' => $item->title,
                    'scheduled_at' => optional($item->scheduled_at)->toDateTimeString(),
                ],
            ];

            $comparisonTexts = array_values(array_unique(array_filter(array_merge(
                $recentCaptions,
                $planCaptions,
                $planTitles
            ))));

            $bestGen = null;
            $bestScore = 1.0;
            $similarityFeedback = null;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $iterContext = $context;
                if ($similarityFeedback !== null) {
                    $iterContext['generation_guard'] = [
                        'retry' => $attempt + 1,
                        'reason' => 'La caption precedente era troppo simile a contenuti esistenti.',
                        'most_similar_caption' => $similarityFeedback['text'],
                        'similarity_score' => $similarityFeedback['score'],
                        'instruction' => 'Crea hook, angolo narrativo e CTA chiaramente diversi, restando coerente con la strategia.',
                    ];
                }

                $gen = $openAi->generateContent($iterContext);
                $caption = trim((string) ($gen['caption'] ?? ''));
                $score = $this->maxTextSimilarity($caption, $comparisonTexts);

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestGen = $gen;
                }

                if ($score < 0.72) {
                    $bestGen = $gen;
                    break;
                }

                $similarityFeedback = [
                    'score' => $score,
                    'text' => $this->closestText($caption, $comparisonTexts),
                ];
            }

            $gen = $bestGen ?? [];

            $item->ai_caption = $gen['caption'] ?? $item->ai_caption;
            $item->ai_hashtags = $gen['hashtags'] ?? [];
            $item->ai_cta = $gen['cta'] ?? ($itemBrain['cta'] ?? $item->ai_cta);
            $item->ai_image_prompt = $gen['image_prompt'] ?? $item->ai_image_prompt;
            $item->ai_meta = array_merge($meta, [
                'text_similarity_score' => round($bestScore, 4),
                'text_uniqueness_checked_at' => now()->toDateTimeString(),
            ]);
            $item->save();
        } catch (Throwable $e) {
            if ($this->isQuotaOrRateLimitError($e) || $this->isTransientNetworkError($e)) {
                $reason = $this->isQuotaOrRateLimitError($e)
                    ? 'OpenAI quota/rate-limit'
                    : 'OpenAI rete/DNS non raggiungibile';

                $fallback = $this->fallbackText($item, $tenantProfile, $itemBrain);
                $item->ai_caption = $fallback['caption'];
                $item->ai_hashtags = $fallback['hashtags'];
                $item->ai_cta = $fallback['cta'];
                $item->ai_image_prompt = $fallback['image_prompt'];
                $item->ai_error = 'TEXT fallback: ' . $reason;
                $item->ai_meta = array_merge($meta, [
                    'text_fallback' => true,
                    'text_fallback_reason' => $reason,
                    'text_fallback_at' => now()->toDateTimeString(),
                ]);
                $item->save();
            } else {
                $item->ai_status = 'error';
                $item->ai_error = 'TEXT: ' . $e->getMessage();
                $item->save();

                Log::error('GenerateAiForContentItem text failed', [
                    'content_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        try {
            $prompt = trim((string) ($item->ai_image_prompt ?? ''));
            $brandImageSources = $this->resolveBrandImageSources($strategy, $meta, (int) $item->tenant_id);
            $brandDecision = $this->decideBrandImageUsage($item, $brandImageSources);
            $selectedBrandImage = $brandDecision['path'] ?? null;

            if ($prompt === '') {
                $brandName = data_get($tenantProfile, 'business_name', 'Brand');
                $industry = data_get($tenantProfile, 'industry', '');
                $palette = data_get($strategy, 'brand_references.palette', '');
                $logoPath = data_get($strategy, 'brand_references.logo_path', '');
                $visualRules = data_get($itemBrain, 'image_direction', 'Visual coerente con il brand.');

                $prompt = "Crea un'immagine social quadrata per {$brandName}. "
                    . "Settore: {$industry}. "
                    . "Direzione visiva: {$visualRules}. "
                    . "Palette colore suggerita: {$palette}. "
                    . "Percorso logo di riferimento (solo contesto stilistico): {$logoPath}. "
                    . ($selectedBrandImage ? "Parti dall'immagine brand fornita e adattala creativamente a questa strategia di post. " : "Crea la composizione da zero seguendo la strategia e mantenendo novita rispetto ai post precedenti. ")
                    . "Non generare loghi finti, nome brand scritto, watermark o testo sovraimpresso nell'immagine. "
                    . "Se è necessario includere testo grafico nell'immagine, usa solo italiano corretto. "
                    . "Stile professionale, coerente con il brand e totalmente in italiano.";

                $item->ai_image_prompt = $prompt;
                $item->save();
            }

            $recentImageHashes = $this->loadRecentImageHashes($item->tenant_id, $item->id, 24);
            $bytes = null;
            $finalHash = null;
            $imageSourceMode = 'text_to_image';
            $brandSourceUsed = null;
            $imageSourceFallback = null;
            $publicDisk = Storage::disk('public');
            $selectedBrandImageAbs = ($selectedBrandImage && $publicDisk->exists($selectedBrandImage))
                ? $publicDisk->path($selectedBrandImage)
                : null;
            $logoSceneAbs = $this->resolveRasterLogoAbsolutePath($strategy, $meta, (int) $item->tenant_id);
            $embedLogoInScene = $this->shouldEmbedLogoInScene($item, $selectedBrandImageAbs, $logoSceneAbs);

            for ($imgAttempt = 0; $imgAttempt < 2; $imgAttempt++) {
                $attemptPrompt = $prompt;
                if ($imgAttempt > 0) {
                    $attemptPrompt .= ' Crea una composizione visibilmente diversa dai post brand precedenti (nuovo layout, inquadratura e gerarchia visiva).';
                }
                $attemptPrompt .= ' Se compaiono scritte visibili nell immagine, devono essere in italiano naturale e corretto.';

                if ($selectedBrandImageAbs) {
                    try {
                        $editPaths = [$selectedBrandImageAbs];
                        if ($embedLogoInScene && $logoSceneAbs) {
                            $editPaths[] = $logoSceneAbs;
                            $attemptPrompt .= ' Integra il logo reale fornito in scena in modo naturale (es. insegna, foglio, monitor), con effetto realistico e senza testo fittizio.';
                        } else {
                            $attemptPrompt .= ' Non aggiungere loghi o testo brand generati dal modello.';
                        }
                        $attemptPrompt .= ' Mantieni il DNA visivo riconoscibile dell immagine brand fornita (scena, oggetti, inquadratura) adattandola alla strategia del post.';
                        $img = $openAi->generateImageEditBase64($attemptPrompt, $editPaths);
                        $imageSourceMode = 'brand_image_edit';
                        $brandSourceUsed = $selectedBrandImage;
                    } catch (Throwable $editError) {
                        $imageSourceFallback = 'edit_failed_fallback_to_text_to_image';
                        $img = $openAi->generateImageBase64($attemptPrompt);
                        $imageSourceMode = 'text_to_image';
                        $metaFallback = is_array($item->ai_meta) ? $item->ai_meta : [];
                        $metaFallback['image_edit_error'] = Str::limit($editError->getMessage(), 240, '');
                        $metaFallback['image_edit_error_at'] = now()->toDateTimeString();
                        $item->ai_meta = $metaFallback;
                        $item->save();
                    }
                } else {
                    $img = $openAi->generateImageBase64($attemptPrompt);
                    $imageSourceMode = 'text_to_image';
                }
                $candidateBytes = base64_decode((string) ($img['b64'] ?? ''), true);

                if ($candidateBytes === false || $candidateBytes === '') {
                    continue;
                }

                $candidateHash = $this->computeImageHashFromBytes($candidateBytes);
                if ($candidateHash === null) {
                    $bytes = $candidateBytes;
                    $finalHash = null;
                    break;
                }

                $similarity = $this->maxImageHashSimilarity($candidateHash, $recentImageHashes);
                if ($similarity < 0.9 || $imgAttempt === 1) {
                    $bytes = $candidateBytes;
                    $finalHash = $candidateHash;
                    break;
                }
            }

            if (is_string($bytes) && $bytes !== '') {
                $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
                Storage::disk('public')->put($filename, $bytes);
                $item->ai_image_path = $filename;
                $metaNow = is_array($item->ai_meta) ? $item->ai_meta : [];
                $metaNow['image_generation'] = [
                    'source' => $imageSourceMode,
                    'brand_source_path' => $brandSourceUsed,
                    'logo_in_scene' => ($imageSourceMode === 'brand_image_edit') ? $embedLogoInScene : false,
                    'brand_selection' => $brandDecision,
                    'fallback' => $imageSourceFallback,
                    'image_hash' => $finalHash,
                    'generated_at' => now()->toDateTimeString(),
                ];
                $item->ai_meta = $metaNow;

                $assets = is_array($item->assets) ? $item->assets : [];
                if ($brandSourceUsed) {
                    $assets[] = ['type' => 'brand_source', 'path' => $brandSourceUsed];
                }
                $assets[] = ['type' => 'ai_generated', 'path' => $filename];
                $item->assets = $this->uniqueAssets($assets);
                $item->save();
            }
        } catch (Throwable $e) {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $meta['image_error'] = $e->getMessage();
            $meta['image_error_at'] = now()->toDateTimeString();
            $item->ai_image_path = null;
            $meta['image_fallback'] = null;
            $meta['image_fallback_reason'] = null;
            $meta['image_fallback_at'] = null;

            $item->ai_meta = $meta;
            $item->save();

            Log::warning('GenerateAiForContentItem image failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Overlay disabilitato: logo/asset vanno integrati in-scene dalla generazione AI.

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }

    private function isQuotaOrRateLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'status code 429')
            || str_contains($message, 'exceeded your current quota')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'insufficient_quota');
    }

    private function isTransientNetworkError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 6')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'curl error 7')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'temporary failure in name resolution');
    }

    private function fallbackText(ContentItem $item, array $tenantProfile, array $itemBrain): array
    {
        $business = trim((string) data_get($tenantProfile, 'business_name', 'Brand'));
        $angle = trim((string) data_get($itemBrain, 'angle', $item->title ?: 'Contenuto'));
        $objective = trim((string) data_get($itemBrain, 'objective', 'Awareness'));
        $cta = trim((string) data_get($itemBrain, 'cta', 'Scrivici per maggiori dettagli.'));
        $industry = trim((string) data_get($tenantProfile, 'industry', 'business'));

        $caption = "{$business}: {$angle}. "
            . "Obiettivo: {$objective}. "
            . "Contenuto generato in fallback temporaneo per limite quota AI.";

        $hashtags = [
            '#marketingdigitale',
            '#contenuti',
            '#' . Str::slug($industry),
            '#strategiabrand',
        ];

        $imagePrompt = "Visual social quadrato per {$business}. "
            . "Tema: {$angle}. Stile pulito e professionale, senza testo sovraimpresso. "
            . "Evita loghi finti, watermark e testo brand inventato. Tutto in italiano.";

        return [
            'caption' => $caption,
            'hashtags' => $hashtags,
            'cta' => $cta,
            'image_prompt' => $imagePrompt,
        ];
    }

    private function isImageBillingLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'billing hard limit')
            || str_contains($message, 'billing_limit_user_error')
            || str_contains($message, 'insufficient_quota');
    }

    private function createLocalImagePlaceholder(ContentItem $item, array $tenantProfile): ?string
    {
        try {
            $brand = trim((string) data_get($tenantProfile, 'business_name', 'Brand'));
            $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $brand), 0, 2) ?: 'BR');
            $palette = (string) data_get($tenantProfile, 'brand_palette', '#0f172a,#2563eb');
            $parts = array_values(array_filter(array_map('trim', explode(',', $palette))));
            $c1 = $parts[0] ?? '#0f172a';
            $c2 = $parts[1] ?? '#2563eb';

            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$c1}"/>
      <stop offset="100%" stop-color="{$c2}"/>
    </linearGradient>
  </defs>
  <rect width="1024" height="1024" fill="url(#g)"/>
  <circle cx="512" cy="512" r="260" fill="rgba(255,255,255,0.16)"/>
  <text x="50%" y="53%" dominant-baseline="middle" text-anchor="middle"
        font-family="Arial, Helvetica, sans-serif" font-size="220" font-weight="700" fill="#ffffff">{$initials}</text>
</svg>
SVG;

            $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.svg';
            Storage::disk('public')->put($filename, $svg);

            return $filename;
        } catch (Throwable) {
            return null;
        }
    }

    private function applyBrandLogoOverlay(ContentItem $item, array $strategy, array $meta): ?array
    {
        try {
            $imageSource = (string) data_get($meta, 'image_generation.source', '');
            if (!$this->shouldApplyLogoOverlay($item, $imageSource)) {
                return ['applied' => false, 'reason' => 'overlay_policy_skip'];
            }

            $imagePath = trim((string) $item->ai_image_path);
            if ($imagePath === '') {
                return ['applied' => false, 'reason' => 'missing_image'];
            }

            $logoPath = $this->resolveLogoPath($strategy, $meta, (int) $item->tenant_id);
            if ($logoPath === null) {
                return ['applied' => false, 'reason' => 'missing_logo_asset'];
            }

            $disk = Storage::disk('public');
            if (!$disk->exists($imagePath)) {
                return ['applied' => false, 'reason' => 'image_not_found'];
            }
            if (!$disk->exists($logoPath)) {
                return ['applied' => false, 'reason' => 'logo_not_found'];
            }

            $imageAbs = $disk->path($imagePath);
            $logoAbs = $disk->path($logoPath);
            $imgInfo = @getimagesize($imageAbs);
            $logoInfo = @getimagesize($logoAbs);

            if (!is_array($imgInfo) || !isset($imgInfo['mime'])) {
                return ['applied' => false, 'reason' => 'invalid_target_image'];
            }
            if (!is_array($logoInfo) || !isset($logoInfo['mime'])) {
                return ['applied' => false, 'reason' => 'invalid_logo_image_or_svg'];
            }

            $target = $this->loadRasterImage($imageAbs, (string) $imgInfo['mime']);
            $logo = $this->loadRasterImage($logoAbs, (string) $logoInfo['mime']);
            if (!$target || !$logo) {
                if (is_resource($target) || $target instanceof \GdImage) {
                    imagedestroy($target);
                }
                if (is_resource($logo) || $logo instanceof \GdImage) {
                    imagedestroy($logo);
                }
                return ['applied' => false, 'reason' => 'unsupported_image_format'];
            }

            $tw = imagesx($target);
            $th = imagesy($target);
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            if ($tw < 10 || $th < 10 || $lw < 2 || $lh < 2) {
                imagedestroy($target);
                imagedestroy($logo);
                return ['applied' => false, 'reason' => 'invalid_dimensions'];
            }

            $maxLogoW = max(90, (int) round($tw * 0.2));
            $maxLogoH = max(90, (int) round($th * 0.2));
            $scale = min($maxLogoW / $lw, $maxLogoH / $lh, 1.0);
            $newW = max(1, (int) round($lw * $scale));
            $newH = max(1, (int) round($lh * $scale));

            $logoResized = imagecreatetruecolor($newW, $newH);
            imagealphablending($logoResized, false);
            imagesavealpha($logoResized, true);
            $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
            imagefilledrectangle($logoResized, 0, 0, $newW, $newH, $transparent);
            imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $newW, $newH, $lw, $lh);

            $style = $this->overlayStyleForItem($item, $tw, $th, $newW, $newH);
            if (($style['opacity'] ?? 1.0) < 1.0) {
                $this->applyOpacity($logoResized, (float) $style['opacity']);
            }

            $angle = (float) ($style['angle'] ?? 0.0);
            if (abs($angle) > 0.001) {
                $rotated = imagerotate($logoResized, $angle, imagecolorallocatealpha($logoResized, 0, 0, 0, 127));
                if ($rotated !== false) {
                    imagesavealpha($rotated, true);
                    imagedestroy($logoResized);
                    $logoResized = $rotated;
                    $newW = imagesx($logoResized);
                    $newH = imagesy($logoResized);
                }
            }

            imagealphablending($target, true);
            imagesavealpha($target, true);

            $x = (int) max(0, min($tw - $newW, (int) ($style['x'] ?? ($tw - $newW))));
            $y = (int) max(0, min($th - $newH, (int) ($style['y'] ?? ($th - $newH))));
            imagecopy($target, $logoResized, $x, $y, 0, 0, $newW, $newH);

            $saved = $this->saveRasterImage($target, $imageAbs, (string) $imgInfo['mime']);

            imagedestroy($logoResized);
            imagedestroy($target);
            imagedestroy($logo);

            if (!$saved) {
                return ['applied' => false, 'reason' => 'save_failed'];
            }

            return [
                'applied' => true,
                'logo_path' => $logoPath,
                'position' => $style['name'] ?? 'overlay',
                'size_ratio' => round($newW / max(1, $tw), 4),
                'applied_at' => now()->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            return [
                'applied' => false,
                'reason' => 'overlay_exception',
                'error' => Str::limit($e->getMessage(), 160, ''),
            ];
        }
    }

    private function shouldApplyLogoOverlay(ContentItem $item, string $imageSource): bool
    {
        return false;
    }

    private function overlayStyleForItem(ContentItem $item, int $tw, int $th, int $w, int $h): array
    {
        $m = max(12, (int) round(min($tw, $th) * 0.03));
        $seed = ($item->id + $this->positionInPlan($item)) % 4;

        return match ($seed) {
            0 => [
                'name' => 'corner-bottom-right',
                'x' => $tw - $w - $m,
                'y' => $th - $h - $m,
                'angle' => 0,
                'opacity' => 0.95,
            ],
            1 => [
                'name' => 'corner-top-left',
                'x' => $m,
                'y' => $m,
                'angle' => 0,
                'opacity' => 0.9,
            ],
            2 => [
                'name' => 'corner-top-right',
                'x' => $tw - $w - $m,
                'y' => $m,
                'angle' => 0,
                'opacity' => 0.9,
            ],
            default => [
                'name' => 'corner-bottom-left',
                'x' => $m,
                'y' => $th - $h - $m,
                'angle' => 0,
                'opacity' => 0.92,
            ],
        };
    }

    private function applyOpacity($img, float $opacity): void
    {
        $opacity = max(0.15, min(1.0, $opacity));
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $alphaFloat = 1.0 - ($a / 127.0);
                $alphaFloat *= $opacity;
                $newA = 127 - (int) round(127 * $alphaFloat);
                $newA = max(0, min(127, $newA));

                $color = imagecolorallocatealpha($img, $r, $g, $b, $newA);
                imagesetpixel($img, $x, $y, $color);
            }
        }
    }

    private function resolveLogoPath(array $strategy, array $meta, int $tenantId): ?string
    {
        $logoPath = trim((string) data_get($strategy, 'brand_references.logo_path', ''));
        if ($logoPath !== '') {
            return $logoPath;
        }

        $assets = (array) data_get($meta, 'brand_assets', []);
        foreach ($assets as $asset) {
            if (($asset['kind'] ?? null) === 'logo' && !empty($asset['path'])) {
                return (string) $asset['path'];
            }
        }

        $dbLogo = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->where('kind', 'logo')
            ->orderByDesc('id')
            ->first(['path']);

        if ($dbLogo && !empty($dbLogo->path)) {
            return (string) $dbLogo->path;
        }

        return null;
    }

    private function resolveRasterLogoAbsolutePath(array $strategy, array $meta, int $tenantId): ?string
    {
        $logoRel = $this->resolveLogoPath($strategy, $meta, $tenantId);
        if (!$logoRel) {
            return null;
        }
        $disk = Storage::disk('public');
        if (!$disk->exists($logoRel)) {
            return null;
        }
        $abs = $disk->path($logoRel);
        $mime = strtolower((string) (mime_content_type($abs) ?: ''));
        if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }
        return $abs;
    }

    private function shouldEmbedLogoInScene(ContentItem $item, ?string $selectedBrandImageAbs, ?string $logoAbs): bool
    {
        if (!$selectedBrandImageAbs || !$logoAbs) {
            return false;
        }
        // Saltuario e deterministico (~1 ogni 3 post).
        return ($this->positionInPlan($item) % 3) === 0;
    }

    private function loadRasterImage(string $path, string $mime)
    {
        return match (strtolower($mime)) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function saveRasterImage($image, string $path, string $mime): bool
    {
        return match (strtolower($mime)) {
            'image/png' => @imagepng($image, $path, 9),
            'image/jpeg', 'image/jpg' => @imagejpeg($image, $path, 92),
            'image/webp' => function_exists('imagewebp') ? @imagewebp($image, $path, 90) : false,
            default => false,
        };
    }

    private function maxTextSimilarity(string $text, array $candidates): float
    {
        $text = $this->normalizeText($text);
        if ($text === '' || empty($candidates)) {
            return 0.0;
        }

        $max = 0.0;
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeText((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $score = $this->textSimilarityScore($text, $candidate);
            if ($score > $max) {
                $max = $score;
            }
        }
        return $max;
    }

    private function closestText(string $text, array $candidates): ?string
    {
        $base = $this->normalizeText($text);
        $best = null;
        $bestScore = -1.0;
        foreach ($candidates as $candidate) {
            $c = $this->normalizeText((string) $candidate);
            if ($c === '') {
                continue;
            }
            $score = $this->textSimilarityScore($base, $c);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $candidate;
            }
        }
        return $best;
    }

    private function textSimilarityScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $ta = array_values(array_filter(explode(' ', $a)));
        $tb = array_values(array_filter(explode(' ', $b)));
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }

        $ia = array_count_values($ta);
        $ib = array_count_values($tb);
        $shared = 0;
        foreach ($ia as $token => $count) {
            if (isset($ib[$token])) {
                $shared += min($count, $ib[$token]);
            }
        }
        $union = max(1, array_sum($ia) + array_sum($ib) - $shared);
        $jaccard = $shared / $union;

        similar_text($a, $b, $percent);
        $charScore = $percent / 100.0;

        return min(1.0, ($jaccard * 0.65) + ($charScore * 0.35));
    }

    private function normalizeText(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    private function resolveBrandImageSources(array $strategy, array $meta, int $tenantId): array
    {
        $paths = (array) data_get($strategy, 'brand_references.reference_images', []);
        $paths = array_values(array_filter(array_map('strval', $paths)));

        if (empty($paths)) {
            $assets = (array) data_get($meta, 'brand_assets', []);
            foreach ($assets as $asset) {
                if (($asset['kind'] ?? null) === 'image' && !empty($asset['path'])) {
                    $paths[] = (string) $asset['path'];
                }
            }
        }

        if (empty($paths)) {
            $dbAssets = BrandAsset::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('content_plan_id')
                ->where('kind', 'image')
                ->orderByDesc('id')
                ->limit(48)
                ->get(['path']);

            foreach ($dbAssets as $asset) {
                if (!empty($asset->path)) {
                    $paths[] = (string) $asset->path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function decideBrandImageUsage(ContentItem $item, array $paths): array
    {
        $public = Storage::disk('public');
        $valid = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !$public->exists($path)) {
                continue;
            }
            $mime = mime_content_type($public->path($path)) ?: '';
            $mime = strtolower($mime);
            if (str_starts_with($mime, 'image/') && !str_contains($mime, 'svg')) {
                $valid[] = $path;
            }
        }
        $valid = array_values(array_unique($valid));
        if (empty($valid)) {
            return ['use_brand' => false, 'path' => null, 'reason' => 'no_valid_brand_images'];
        }

        $position = $this->positionInPlan($item);
        $totalInPlan = $this->totalItemsInPlan($item);
        $used = $this->usedBrandImagePathsInPlan($item->tenant_id, $item->content_plan_id, $item->id);
        $unused = array_values(array_diff($valid, $used));
        if (!empty($unused)) {
            // Priorita forte agli asset brand: usa prima tutte le immagini disponibili, una sola volta ciascuna.
            $idx = $position % count($unused);
            return [
                'use_brand' => true,
                'path' => $unused[$idx],
                'reason' => 'priority_brand_image_unused',
                'position' => $position,
                'total_in_plan' => $totalInPlan,
                'unused_pool' => count($unused),
            ];
        }

        return ['use_brand' => false, 'path' => null, 'reason' => 'all_brand_images_already_used'];
    }

    private function totalItemsInPlan(ContentItem $item): int
    {
        return (int) ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->count();
    }

    private function positionInPlan(ContentItem $item): int
    {
        $ids = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $position = array_search($item->id, $ids, true);
        if ($position === false) {
            return max(0, count($ids) - 1);
        }
        return (int) $position;
    }

    private function usedBrandImagePathsInPlan(int $tenantId, int $contentPlanId, int $excludeItemId): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('content_plan_id', $contentPlanId)
            ->where('id', '!=', $excludeItemId)
            ->whereNotNull('ai_meta')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['ai_meta']);

        $used = [];
        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $path = data_get($meta, 'image_generation.brand_source_path');
            if (is_string($path) && $path !== '') {
                $used[] = $path;
            }
        }

        return array_values(array_unique($used));
    }

    private function planAlreadyUsedBrandImage(ContentItem $item): bool
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_meta')
            ->get(['ai_meta']);

        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            if ((string) data_get($meta, 'image_generation.source', '') === 'brand_image_edit') {
                return true;
            }
        }

        return false;
    }

    private function computeImageHashFromBytes(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }

        $thumb = imagecreatetruecolor(8, 8);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));

        $vals = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $vals[] = (int) round(($r + $g + $b) / 3);
            }
        }
        $avg = array_sum($vals) / max(1, count($vals));

        $bits = '';
        foreach ($vals as $v) {
            $bits .= ($v >= $avg) ? '1' : '0';
        }

        imagedestroy($thumb);
        imagedestroy($img);

        return $bits;
    }

    private function loadRecentImageHashes(int $tenantId, int $excludeItemId, int $limit = 24): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $excludeItemId)
            ->whereNotNull('ai_image_path')
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['ai_image_path', 'ai_meta']);

        $out = [];
        $disk = Storage::disk('public');
        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $hash = data_get($meta, 'image_generation.image_hash');
            if (is_string($hash) && strlen($hash) === 64) {
                $out[] = $hash;
                continue;
            }

            $path = (string) $row->ai_image_path;
            if ($path === '' || !$disk->exists($path)) {
                continue;
            }
            $bytes = $disk->get($path);
            $computed = $this->computeImageHashFromBytes($bytes);
            if ($computed !== null) {
                $out[] = $computed;
            }
        }

        return array_values(array_unique($out));
    }

    private function maxImageHashSimilarity(?string $hash, array $otherHashes): float
    {
        if (!$hash || empty($otherHashes)) {
            return 0.0;
        }
        $max = 0.0;
        foreach ($otherHashes as $other) {
            if (!is_string($other) || strlen($other) !== strlen($hash)) {
                continue;
            }
            $distance = 0;
            for ($i = 0; $i < strlen($hash); $i++) {
                if ($hash[$i] !== $other[$i]) {
                    $distance++;
                }
            }
            $sim = 1.0 - ($distance / max(1, strlen($hash)));
            if ($sim > $max) {
                $max = $sim;
            }
        }
        return $max;
    }

    private function loadBrandAssetsFromDb(int $tenantId): array
    {
        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->orderByDesc('id')
            ->limit(48)
            ->get(['id', 'kind', 'path', 'original_name', 'mime'])
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

    private function mergeBrandAssets(array $fromMeta, array $fromDb): array
    {
        $all = array_merge($fromMeta, $fromDb);
        $out = [];
        $seen = [];
        foreach ($all as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $kind = trim((string) ($asset['kind'] ?? ''));
            $path = trim((string) ($asset['path'] ?? ''));
            if ($kind === '' || $path === '') {
                continue;
            }
            $key = $kind . '|' . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'id' => isset($asset['id']) ? (int) $asset['id'] : null,
                'kind' => $kind,
                'path' => $path,
                'original_name' => (string) ($asset['original_name'] ?? ''),
                'mime' => (string) ($asset['mime'] ?? ''),
            ];
        }
        return $out;
    }

    private function uniqueAssets(array $assets): array
    {
        $out = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $path = (string) ($asset['path'] ?? '');
            $type = (string) ($asset['type'] ?? '');
            if ($path === '') {
                continue;
            }
            $key = $type . '|' . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['type' => $type ?: 'asset', 'path' => $path];
        }
        return $out;
    }
}

