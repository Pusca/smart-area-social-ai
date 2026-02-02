<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateContentItemAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $contentItemId) {}

    public function handle(OpenAiService $ai): void
    {
        $item = ContentItem::query()->findOrFail($this->contentItemId);

        $item->update([
            'ai_status' => 'generating',
            'ai_error' => null,
        ]);

        try {
            $plan = $item->plan; // relazione
            $brand = $plan?->name ?? 'Smartera';

            $pkg = $ai->generatePostPackage([
                'brand' => 'Smartera (Smart Area Social AI)',
                'goal' => $plan?->settings['goal'] ?? 'Lead + Awareness + Autorità',
                'tone' => $plan?->settings['tone'] ?? 'professionale',
                'platforms' => implode(', ', (array)($plan?->settings['platforms'] ?? ['instagram','facebook'])),
                'format' => $item->format ?? ($plan?->settings['formats'][0] ?? 'post'),
                'title' => $item->title ?? 'Contenuto',
                'date' => (string)($item->scheduled_for ?? ''),
                'services' => $plan?->settings['services'] ?? 'Siti web, automazioni, AI, marketing',
                'target' => $plan?->settings['target'] ?? 'PMI e attività locali che vogliono automatizzare i social',
                'business' => $plan?->settings['business'] ?? 'Agenzia digitale e automazioni AI',
                'extra' => $plan?->settings['extra'] ?? '',
            ]);

            $imagePrompt = (string)($pkg['image_prompt'] ?? 'Immagine moderna e pulita per un post social di Smartera.');
            $b64 = $ai->generateImageBase64($imagePrompt);

            // Salvo immagine su storage pubblico
            $bin = base64_decode($b64);
            $path = 'generated/'.date('Ymd_His').'_content_'.$item->id.'.png';
            Storage::disk('public')->put($path, $bin);

            $item->update([
                'ai_caption' => (string)($pkg['caption'] ?? ''),
                'ai_hashtags' => $pkg['hashtags'] ?? [],
                'ai_cta' => (string)($pkg['cta'] ?? ''),
                'ai_image_prompt' => $imagePrompt,
                'ai_image_path' => $path,
                'ai_status' => 'ready',
                'ai_generated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $item->update([
                'ai_status' => 'error',
                'ai_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
