<?php

namespace App\Support;

use App\Jobs\GenerateAiForContentItem;

class GenerationExecution
{
    public static function shouldRunSync(): bool
    {
        return app()->environment('local');
    }

    public static function shouldDispatchAfterResponse(): bool
    {
        return !self::shouldRunSync()
            && !app()->runningInConsole()
            && !app()->runningUnitTests()
            && (bool) config('generation.force_sync', false);
    }

    public static function shouldShowProgressPage(): bool
    {
        return !app()->runningUnitTests() && !self::shouldRunSync();
    }

    public static function dispatchContentItem(int $contentItemId): void
    {
        if (self::shouldRunSync()) {
            GenerateAiForContentItem::dispatchSync($contentItemId);
            return;
        }

        if (self::shouldDispatchAfterResponse()) {
            GenerateAiForContentItem::dispatchAfterResponse($contentItemId);
            return;
        }

        GenerateAiForContentItem::dispatch($contentItemId);
    }
}
