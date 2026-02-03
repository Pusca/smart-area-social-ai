<?php

namespace App\Jobs;

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

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $item = ContentItem::findOrFail($this->contentItemId);

        // Stato iniziale
        $item->ai_status = 'pending';
        $item->ai_error = null;

        $meta = $item->ai_meta ?? [];
        if (!is_array($meta)) $meta = [];

        $meta['debug'] = array_merge($meta['debug'] ?? [], [
            'job' => 'GenerateAiForContentItem::JOBv4',
            'openai_base_url_config' => config('openai.base_url'),
            'openai_text_model' => config('openai.text_model'),
            'openai_image_model' => config('openai.image_model'),
            'time' => now()->toDateTimeString(),
        ]);

        $item->ai_meta = $meta;
        $item->save();

        // 1) TESTO (hard fail se non va)
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
            $msg = "JOBv4 | TEXT | " . $e->getMessage();

            $item->ai_status = 'error';
            $item->ai_error = $msg;
            $item->save();

            Log::error('GenerateAiForContentItem JOBv4 text failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

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
            $item->save();

            Log::warning('GenerateAiForContentItem JOBv4 image failed', [
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
