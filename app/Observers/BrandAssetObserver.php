<?php

namespace App\Observers;

use App\Jobs\AnalyzeBrandAssetJob;
use App\Models\BrandAsset;

class BrandAssetObserver
{
    public function created(BrandAsset $asset): void
    {
        if (!$asset->isImage()) {
            return;
        }

        // Dispatch con 3s di delay per dare tempo allo storage di scrivere il file
        AnalyzeBrandAssetJob::dispatch($asset->id)->delay(now()->addSeconds(3));
    }
}
