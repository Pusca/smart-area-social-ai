<?php

namespace App\Jobs;

use App\Models\BrandAsset;
use App\Models\TenantProfile;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Guarda le foto reali del brand (logo, locale, prodotti) e ne distilla
 * una guida di stile visivo, usata poi da tutti gli image_prompt.
 */
class AnalyzeBrandVisuals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    public function __construct(public int $tenantId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $profile = TenantProfile::where('tenant_id', $this->tenantId)->first();
        if (!$profile) {
            return;
        }

        $assets = BrandAsset::query()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('content_plan_id')
            ->latest('id')
            ->limit(8)
            ->get();

        $images = [];
        foreach ($assets as $asset) {
            if (count($images) >= 4) {
                break;
            }

            $mime = (string) ($asset->mime ?: 'image/jpeg');
            // La vision API non accetta SVG
            if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
                continue;
            }
            if (!$asset->path || !Storage::disk('public')->exists($asset->path)) {
                continue;
            }

            $bytes = Storage::disk('public')->get($asset->path);
            if ($bytes === null || strlen($bytes) === 0 || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                continue;
            }

            $images[] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        if ($images === []) {
            return;
        }

        try {
            $style = $openAi->describeBrandVisuals($images);
            if ($style !== '') {
                $profile->visual_style = $style;
                $profile->save();
            }
        } catch (Throwable $e) {
            Log::warning('AnalyzeBrandVisuals failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
