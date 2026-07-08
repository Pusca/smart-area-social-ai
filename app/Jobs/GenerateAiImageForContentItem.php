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
use Throwable;

/**
 * Rigenera SOLO l'immagine di un ContentItem, senza toccare il testo.
 */
class GenerateAiImageForContentItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public int $contentItemId)
    {
    }

    public function handle(ContentAiGenerator $generator): void
    {
        $item = ContentItem::find($this->contentItemId);
        if (!$item) {
            return;
        }

        try {
            $generator->generateImage($item);
        } catch (Throwable $e) {
            $generator->markImageError($item, $e->getMessage());
            Log::warning('GenerateAiImageForContentItem failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Il testo non è stato toccato: non lasciare l'item bloccato in coda
        if ($item->ai_status === 'queued') {
            $item->ai_status = 'done';
            $item->save();
        }
    }
}
