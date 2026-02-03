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

class GenerateAiImageForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $item = ContentItem::findOrFail($this->contentItemId);

        // Mettiamo queued/pending, ma NON tocchiamo caption ecc.
        $item->ai_status = 'pending';
        $item->save();

        try {
            $prompt = trim((string) ($item->ai_image_prompt ?? ''));

            // Fallback prompt se vuoto: lo costruiamo da brand + caption + platform/format
            if ($prompt === '') {
                $brandName = data_get($item, 'ai_meta.brand.business_name', 'Brand');
                $industry  = data_get($item, 'ai_meta.brand.industry', '');
                $goal      = data_get($item, 'ai_meta.plan.goal', '');
                $tone      = data_get($item, 'ai_meta.plan.tone', '');

                $caption = trim((string) ($item->ai_caption ?? ''));
                if ($caption === '') {
                    $caption = $item->title ?? '';
                }

                $prompt = "Create a high-quality square social media image (1024x1024) for {$brandName}. "
                        . "Industry: {$industry}. Goal: {$goal}. Tone: {$tone}. "
                        . "Platform: {$item->platform}. Format: {$item->format}. "
                        . "Visual concept based on: {$caption}. "
                        . "No text in the image, modern minimal design, strong contrast, professional look.";
                $item->ai_image_prompt = $prompt;
                $item->save();
            }

            $img = $openAi->generateImageBase64($prompt);
            $bytes = base64_decode($img['b64']);

            $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
            Storage::disk('public')->put($filename, $bytes);

            $item->ai_image_path = $filename;

            // Se il testo esiste già, lasciamo done. Se non c’è, almeno non blocchiamo.
            $item->ai_status = $item->ai_caption ? 'done' : 'pending';
            $item->save();
        } catch (Throwable $e) {
            // Best-effort: non distruggiamo il post, ma salviamo l’errore
            $msg = "IMAGE_ONLY | " . $e->getMessage();

            $meta = $item->ai_meta ?? [];
            if (!is_array($meta)) $meta = [];
            $meta['image_error'] = $msg;
            $meta['image_error_at'] = now()->toDateTimeString();
            $item->ai_meta = $meta;

            // Se caption c’è, restiamo done; altrimenti error
            $item->ai_status = $item->ai_caption ? 'done' : 'error';
            $item->save();

            Log::warning('GenerateAiImageForContentItem failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
