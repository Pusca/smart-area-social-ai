<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Services\ContentAiGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateAiForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;
    public int $tries = 3;

    public function __construct(public int $contentItemId)
    {
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(ContentAiGenerator $generator): void
    {
        $item = ContentItem::find($this->contentItemId);
        if (!$item) {
            return;
        }

        $item->ai_status = 'pending';
        $item->ai_error = null;
        $item->save();

        // 1) TESTO: se fallisce il job va in retry (backoff) e poi in failed()
        $generator->generateText($item);

        // 2) IMMAGINE: best-effort, il post resta valido anche senza
        try {
            $generator->generateImage($item);
        } catch (Throwable $e) {
            $generator->markImageError($item, $e->getMessage());
            Log::warning('GenerateAiForContentItem image failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }

    public function failed(Throwable $e): void
    {
        $item = ContentItem::find($this->contentItemId);
        if (!$item) {
            return;
        }

        $item->ai_status = 'error';
        $item->ai_error = Str::limit($e->getMessage(), 500);
        $item->save();

        Log::error('GenerateAiForContentItem failed', [
            'content_item_id' => $item->id,
            'error' => $e->getMessage(),
        ]);
    }
}
