<?php

namespace App\Jobs;

use App\Models\BrandAsset;
use App\Services\AI\ImageUnderstandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeBrandAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(public int $brandAssetId)
    {
    }

    public function handle(ImageUnderstandingService $service): void
    {
        $asset = BrandAsset::find($this->brandAssetId);

        if (!$asset || !$asset->isImage()) {
            return;
        }

        // Salta se già analizzato (retry manuale possibile dall'UI)
        if ($asset->hasAiUnderstanding()) {
            return;
        }

        $result = $service->analyze((string) $asset->path);

        if ($result === null) {
            Log::info('AnalyzeBrandAssetJob: no result', ['asset_id' => $asset->id]);
            return;
        }

        $asset->update([
            'ai_description' => $result['description'],
            'ai_context'     => $result['context'],
            'ai_tags'        => $result['tags'],
            'ai_analyzed_at' => now(),
        ]);

        Log::info('AnalyzeBrandAssetJob: completed', [
            'asset_id' => $asset->id,
            'context'  => $result['context'],
            'tags'     => count($result['tags']),
        ]);
    }
}
