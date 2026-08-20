<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\TenantProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Genera testo e immagine per un singolo ContentItem.
 * Il contesto brand viene letto LIVE dal TenantProfile (unica fonte di
 * verità); in ai_meta restano solo topic e usage.
 */
class ContentAiGenerator
{
    public function __construct(protected OpenAiService $openAi)
    {
    }

    public function buildContext(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        $profile = TenantProfile::where('tenant_id', $item->tenant_id)->first();
        $brand = $profile
            ? $profile->toAiContext()
            // fallback per item creati prima del refactor
            : ($meta['brand'] ?? $meta['tenant_profile'] ?? []);

        $planSettings = is_array($item->plan?->settings) ? $item->plan->settings : [];
        $plan = $planSettings !== []
            ? Arr::only($planSettings, ['goal', 'tone', 'platforms', 'formats'])
            : ($meta['plan'] ?? []);

        return [
            'brand' => $brand,
            'plan' => $plan,
            'topic' => $meta['topic'] ?? null,
            'item' => [
                'platform' => $item->platform,
                'format' => $item->format,
                'title' => $item->title,
                'scheduled_at' => optional($item->scheduled_at)->toDateTimeString(),
            ],
        ];
    }

    public function generateText(ContentItem $item): void
    {
        $gen = $this->openAi->generateContent($this->buildContext($item));

        $item->ai_caption = $gen['caption'] ?? $item->ai_caption;
        $item->ai_hashtags = $gen['hashtags'] ?? [];
        $item->ai_cta = $gen['cta'] ?? $item->ai_cta;
        $item->ai_image_prompt = $gen['image_prompt'] ?? $item->ai_image_prompt;

        if (!empty($gen['usage'])) {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
            $meta['usage']['text'] = $gen['usage'];
            $item->ai_meta = $meta;
        }

        $item->save();
    }

    public function generateImage(ContentItem $item): void
    {
        $prompt = trim((string) ($item->ai_image_prompt ?? ''));

        if ($prompt === '') {
            $prompt = $this->fallbackImagePrompt($item);
            $item->ai_image_prompt = $prompt;
            $item->save();
        }

        $img = $this->openAi->generateImageBase64($prompt, null, $this->imageSizeFor($item));
        $bytes = base64_decode($img['b64'], true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Payload immagine base64 non valido');
        }

        $filename = 'ai/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
        Storage::disk('public')->put($filename, $bytes);

        $item->ai_image_path = $filename;

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        unset($meta['image_error'], $meta['image_error_at']);
        $item->ai_meta = $meta;

        $item->save();
    }

    public function markImageError(ContentItem $item, string $message): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['image_error'] = Str::limit($message, 500);
        $meta['image_error_at'] = now()->toDateTimeString();
        $item->ai_meta = $meta;
        $item->save();
    }

    /**
     * Story e reel sono verticali, il resto quadrato.
     */
    protected function imageSizeFor(ContentItem $item): string
    {
        return in_array($item->format, ['story', 'reel'], true)
            ? '1024x1536'
            : (string) (config('openai.image_size') ?: '1024x1024');
    }

    protected function fallbackImagePrompt(ContentItem $item): string
    {
        $ctx = $this->buildContext($item);

        $brandName = (string) data_get($ctx, 'brand.business_name', 'a local business');
        $industry = (string) data_get($ctx, 'brand.industry', '');
        $visual = (string) data_get($ctx, 'brand.visual_style', '');
        $tone = (string) data_get($ctx, 'plan.tone', '');
        $subject = trim((string) ($item->ai_caption ?: $item->title ?: ''));

        return "High-quality social media photo for {$brandName}"
            . ($industry !== '' ? " ({$industry})" : '')
            . ". Visual concept: {$subject}. "
            . ($visual !== '' ? "Brand visual style: {$visual}. " : '')
            . ($tone !== '' ? "Mood: {$tone}. " : '')
            . "Natural light, professional look, modern minimal composition. No text, no logos, no watermarks.";
    }
}
