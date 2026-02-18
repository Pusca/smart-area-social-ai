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
        $item = ContentItem::query()->with('plan')->findOrFail($this->contentItemId);

        $item->ai_status = 'pending';
        $item->ai_error = null;
        $item->save();

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $strategy = data_get($meta, 'strategy', $item->plan?->strategy ?? []);
        $itemBrain = data_get($meta, 'item_brain', []);
        $tenantProfile = data_get($meta, 'tenant_profile', data_get($meta, 'brand', []));
        $memorySummary = data_get($meta, 'memory_summary', []);

        $recentCaptions = ContentItem::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_caption')
            ->whereIn('ai_status', ['done', 'pending'])
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('ai_caption')
            ->map(fn ($caption) => Str::limit((string) $caption, 200, ''))
            ->values()
            ->all();

        try {
            $context = [
                'brand' => $tenantProfile,
                'plan' => data_get($meta, 'plan', []),
                'strategy' => $strategy,
                'item_brain' => $itemBrain,
                'memory_summary' => $memorySummary,
                'repetition_rules' => [
                    'avoid_list' => array_values(array_unique(array_filter(array_merge(
                        (array) data_get($itemBrain, 'avoid_list', []),
                        (array) data_get($strategy, 'repetition_guard.avoid_terms', []),
                        (array) data_get($memorySummary, 'avoid_repetition', [])
                    )))),
                    'recent_captions' => $recentCaptions,
                ],
                'item' => [
                    'platform' => $item->platform,
                    'format' => $item->format,
                    'title' => $item->title,
                    'scheduled_at' => optional($item->scheduled_at)->toDateTimeString(),
                ],
            ];

            $gen = $openAi->generateContent($context);

            $item->ai_caption = $gen['caption'] ?? $item->ai_caption;
            $item->ai_hashtags = $gen['hashtags'] ?? [];
            $item->ai_cta = $gen['cta'] ?? ($itemBrain['cta'] ?? $item->ai_cta);
            $item->ai_image_prompt = $gen['image_prompt'] ?? $item->ai_image_prompt;
            $item->save();
        } catch (Throwable $e) {
            $item->ai_status = 'error';
            $item->ai_error = 'TEXT: ' . $e->getMessage();
            $item->save();

            Log::error('GenerateAiForContentItem text failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        try {
            $prompt = trim((string) ($item->ai_image_prompt ?? ''));
            if ($prompt === '') {
                $brandName = data_get($tenantProfile, 'business_name', 'Brand');
                $industry = data_get($tenantProfile, 'industry', '');
                $palette = data_get($strategy, 'brand_references.palette', '');
                $logoPath = data_get($strategy, 'brand_references.logo_path', '');
                $visualRules = data_get($itemBrain, 'image_direction', 'Visual coerente con il brand.');

                $prompt = "Create a square social image for {$brandName}. "
                    . "Industry: {$industry}. "
                    . "Visual direction: {$visualRules}. "
                    . "Color palette hint: {$palette}. "
                    . "Logo reference path: {$logoPath}. "
                    . "No text overlay. Professional and brand-consistent style.";

                $item->ai_image_prompt = $prompt;
                $item->save();
            }

            $img = $openAi->generateImageBase64($prompt);
            $bytes = base64_decode((string) ($img['b64'] ?? ''), true);

            if ($bytes !== false && $bytes !== '') {
                $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
                Storage::disk('public')->put($filename, $bytes);
                $item->ai_image_path = $filename;
                $item->save();
            }
        } catch (Throwable $e) {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $meta['image_error'] = $e->getMessage();
            $meta['image_error_at'] = now()->toDateTimeString();
            $item->ai_meta = $meta;
            $item->save();

            Log::warning('GenerateAiForContentItem image failed', [
                'content_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        $item->ai_status = 'done';
        $item->ai_generated_at = now();
        $item->save();
    }
}
