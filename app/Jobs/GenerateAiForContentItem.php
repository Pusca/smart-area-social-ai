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
use RuntimeException;
use Throwable;

class GenerateAiForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $item = ContentItem::findOrFail($this->contentItemId);

<<<<<<< HEAD
        // Stato iniziale
=======
        // Stato iniziale job
>>>>>>> de43855 (Fix wizard routes + improve dashboard flow)
        $item->ai_status = 'pending';
        $item->ai_error = null;

        // Modello immagini valido (mai usare modelli testo)
        $validImageModels = [
            'gpt-image-1',
            'gpt-image-1-mini',
            'gpt-image-1.5',
            'chatgpt-image-latest',
            'dall-e-2',
            'dall-e-3',
        ];

        $imageModel = (string) (env('OPENAI_IMAGE_MODEL') ?: config('openai.image_model') ?: 'gpt-image-1');
        $imageModel = trim($imageModel);
        if ($imageModel === '' || !in_array($imageModel, $validImageModels, true)) {
            $imageModel = 'gpt-image-1';
        }

        // Forza a runtime per evitare config cache / vecchi valori
        config(['openai.image_model' => $imageModel]);

        // Debug header
        $meta = $item->ai_meta ?? [];
        if (!is_array($meta)) $meta = [];

        $meta['debug'] = array_merge($meta['debug'] ?? [], [
            'job' => 'GenerateAiForContentItem::JOBv5',
            'openai_base_url_config' => config('openai.base_url'),
            'openai_text_model' => config('openai.text_model'),
            'openai_image_model_effective' => $imageModel,
            'time' => now()->toDateTimeString(),
        ]);
<<<<<<< HEAD

        $item->ai_meta = $meta;
        $item->save();

        // 1) TESTO (hard fail se non va)
=======
        $item->ai_meta = $meta;
        $item->save();

        /**
         * 1) TESTO (se fallisce -> errore vero)
         */
>>>>>>> de43855 (Fix wizard routes + improve dashboard flow)
        try {
            $context = [
                'brand' => data_get($item, 'ai_meta.brand', []),
                'plan'  => data_get($item, 'ai_meta.plan', []),
                'item'  => [
                    'platform' => $item->platform,
                    'format'   => $item->format,
                    'title'    => $item->title,
                    'scheduled_at' => optional($item->scheduled_at)->toDateTimeString(),
                ],
            ];

            $gen = $openAi->generateContent($context);

            $item->ai_caption = $gen['caption'] ?? $item->ai_caption;
            $item->ai_hashtags = $gen['hashtags'] ?? [];
            $item->ai_cta = $gen['cta'] ?? $item->ai_cta;
            $item->ai_image_prompt = $gen['image_prompt'] ?? $item->ai_image_prompt;

            $item->save();
        } catch (Throwable $e) {
<<<<<<< HEAD
            $msg = "JOBv4 | TEXT | " . $e->getMessage();

=======
            $msg = "JOBv5 | TEXT | " . $e->getMessage();
>>>>>>> de43855 (Fix wizard routes + improve dashboard flow)
            $item->ai_status = 'error';
            $item->ai_error = $msg;
            $item->save();

            Log::error('GenerateAiForContentItem JOBv5 text failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

<<<<<<< HEAD
        // 2) IMMAGINE (best-effort)
        try {
            $prompt = trim((string) ($item->ai_image_prompt ?? ''));

            // fallback prompt se vuoto
            if ($prompt === '') {
                $brandName = data_get($item, 'ai_meta.brand.business_name', 'Brand');
                $industry  = data_get($item, 'ai_meta.brand.industry', '');
                $goal      = data_get($item, 'ai_meta.plan.goal', '');
                $tone      = data_get($item, 'ai_meta.plan.tone', '');

                $caption = trim((string) ($item->ai_caption ?? ''));
                if ($caption === '') $caption = (string) ($item->title ?? '');

                $prompt = "Create a high-quality square social media image (1024x1024) for {$brandName}. "
                        . "Industry: {$industry}. Goal: {$goal}. Tone: {$tone}. "
                        . "Platform: {$item->platform}. Format: {$item->format}. "
                        . "Visual concept based on: {$caption}. "
                        . "No text in the image, modern minimal design, professional look.";
                $item->ai_image_prompt = $prompt;
                $item->save();
            }

            $img = $openAi->generateImageBase64($prompt);
            $bytes = base64_decode($img['b64']);

            $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
            Storage::disk('public')->put($filename, $bytes);

            $item->ai_image_path = $filename;
            $item->save();
        } catch (Throwable $e) {
            $warn = "JOBv4 | IMAGE | " . $e->getMessage();

            $meta = $item->ai_meta ?? [];
            if (!is_array($meta)) $meta = [];
            $meta['image_error'] = $warn;
            $meta['image_error_at'] = now()->toDateTimeString();
            $item->ai_meta = $meta;

            // non blocchiamo il post, caption resta valida
=======
        /**
         * 2) IMMAGINE (best-effort)
         * - usa prompt da ai_image_prompt
         * - aggiunge reference assets dal wizard (logo/immagini)
         */
        try {
            $prompt = (string) ($item->ai_image_prompt ?? '');
            $prompt = trim($prompt);

            // Fallback se il modello testo non ha dato image_prompt
            if ($prompt === '') {
                $business = (string) data_get($item, 'ai_meta.brand.business_name', 'Brand');
                $industry = (string) data_get($item, 'ai_meta.brand.industry', 'business');
                $goal     = (string) data_get($item, 'ai_meta.plan.goal', 'lead generation');

                $prompt = "Professionally designed Instagram post visual for {$business} ({$industry}). "
                    . "Clean, modern corporate style. Visual metaphors for AI, automation and marketing. "
                    . "Goal: {$goal}. Minimal, premium, high-contrast layout, no clutter.";
            }

            // Carica assets brand (logo + immagini) dal DB
            $assets = BrandAsset::query()
                ->where('tenant_id', $item->tenant_id)
                ->where(function ($q) use ($item) {
                    $q->whereNull('content_plan_id')
                      ->orWhere('content_plan_id', $item->content_plan_id);
                })
                ->orderByDesc('id')
                ->limit(8)
                ->get();

            $logo = $assets->firstWhere('kind', 'logo');
            $images = $assets->where('kind', 'image')->values();

            // Aggiunge guidance “brand-aware” (senza forzare testo/loghi in output)
            if ($assets->count() > 0) {
                $assetLines = $assets->map(function ($a) {
                    return strtoupper($a->kind) . ': ' . $a->path;
                })->implode("\n");

                $prompt .= "\n\nBrand reference assets (use as identity/style reference):\n" . $assetLines . "\n";
                $prompt .= "Guidelines: match the brand color palette and overall vibe seen in the assets. "
                    . "Keep a consistent, clean corporate look. Use similar shapes/visual language. "
                    . "Do NOT copy or paste logos. Do NOT add any text.\n";
            }

            // Guardrail: evita testo/loghi stampati nell'immagine
            $prompt .= "\nNo text, no watermark, no logo, no brand name lettering.";

            $img = $openAi->generateImageBase64($prompt, $imageModel);

            $b64 = (string)(
                ($img['b64'] ?? null)
                ?? ($img['b64_json'] ?? null)
                ?? data_get($img, 'data.0.b64_json')
                ?? ''
            );
            $b64 = trim($b64);

            if ($b64 === '') {
                throw new RuntimeException('Empty base64 image payload (expected b64/b64_json)');
            }

            $bytes = base64_decode($b64, true);
            if ($bytes === false || strlen($bytes) < 1000) {
                throw new RuntimeException('Invalid base64 decode or too-small image bytes');
            }

            $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
            Storage::disk('public')->put($filename, $bytes);

            $item->ai_image_path = $filename;

            // Debug utile
            $meta = $item->ai_meta ?? [];
            if (!is_array($meta)) $meta = [];
            $meta['debug'] = array_merge($meta['debug'] ?? [], [
                'image_saved' => true,
                'image_path' => $filename,
                'image_model_used' => $imageModel,
                'image_bytes' => strlen($bytes),
                'brand_assets_count' => $assets->count(),
                'brand_logo_present' => (bool) $logo,
                'brand_images_count' => $images->count(),
                'brand_assets_paths' => $assets->pluck('path')->values()->all(),
            ]);
            $item->ai_meta = $meta;

            $item->save();
        } catch (Throwable $e) {
            $warn = "JOBv5 | IMAGE | " . $e->getMessage();

            $item->ai_error = $warn;

            $meta = $item->ai_meta ?? [];
            if (!is_array($meta)) $meta = [];
            $meta['debug'] = array_merge($meta['debug'] ?? [], [
                'image_saved' => false,
                'image_model_used' => $imageModel,
                'image_error' => $e->getMessage(),
            ]);
            $item->ai_meta = $meta;

>>>>>>> de43855 (Fix wizard routes + improve dashboard flow)
            $item->save();

            Log::warning('GenerateAiForContentItem JOBv5 image failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        // done se testo ok
        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }
}
