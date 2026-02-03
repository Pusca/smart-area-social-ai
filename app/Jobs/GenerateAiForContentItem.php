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

        // Firma job
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

        // 1) TESTO: se fallisce, è errore vero
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

            $item->ai_caption = $gen['caption'] ?? null;
            $item->ai_hashtags = $gen['hashtags'] ?? [];
            $item->ai_cta = $gen['cta'] ?? null;
            $item->ai_image_prompt = $gen['image_prompt'] ?? null;

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

        // 2) IMMAGINE: best-effort (se fallisce NON blocca il post)
        try {
            $prompt = (string) ($item->ai_image_prompt ?? '');

            if (trim($prompt) !== '') {
                $img = $openAi->generateImageBase64($prompt);
                $bytes = base64_decode($img['b64']);

                $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
                Storage::disk('public')->put($filename, $bytes);

                $item->ai_image_path = $filename;
                $item->save();
            }
        } catch (Throwable $e) {
            // Non blocchiamo: salviamo info per debug
            $warn = "JOBv4 | IMAGE | " . $e->getMessage();

            $item->ai_error = $warn; // mantiene traccia, ma post resta "done"
            $item->save();

            Log::warning('GenerateAiForContentItem JOBv4 image failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Done comunque se testo ok
        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }
}
