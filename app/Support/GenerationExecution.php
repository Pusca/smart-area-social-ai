<?php

namespace App\Support;

use App\Jobs\GenerateAiForContentItem;

class GenerationExecution
{
    public static function shouldRunSync(): bool
    {
        return app()->environment('local') || (bool) config('generation.force_sync', false);
    }

    public static function dispatchContentItem(int $contentItemId): void
    {
        if (self::shouldRunSync()) {
            GenerateAiForContentItem::dispatchSync($contentItemId);
            return;
        }

        GenerateAiForContentItem::dispatch($contentItemId);
    }
}
