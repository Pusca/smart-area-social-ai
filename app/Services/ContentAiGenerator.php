<?php

namespace App\Services;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Genera testo e immagine per un singolo ContentItem,
 * leggendo il contesto (brand, piano, argomento) da ai_meta.
 */
class ContentAiGenerator
{
    public function __construct(protected OpenAiService $openAi)
    {
    }

    public function buildContext(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];

        return [
            // 'tenant_profile' è la chiave usata dai piani creati prima del refactor
            'brand' => $meta['brand'] ?? $meta['tenant_profile'] ?? [],
            'plan' => $meta['plan'] ?? [],
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

        $img = $this->openAi->generateImageBase64($prompt);
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

    protected function fallbackImagePrompt(ContentItem $item): string
    {
        $ctx = $this->buildContext($item);

        $brandName = (string) data_get($ctx, 'brand.business_name', 'a local business');
        $industry = (string) data_get($ctx, 'brand.industry', '');
        $tone = (string) data_get($ctx, 'plan.tone', '');
        $subject = trim((string) ($item->ai_caption ?: $item->title ?: ''));

        return "High-quality square social media photo for {$brandName}"
            . ($industry !== '' ? " ({$industry})" : '')
            . ". Visual concept: {$subject}. "
            . ($tone !== '' ? "Mood: {$tone}. " : '')
            . "Natural light, professional look, modern minimal composition. No text, no logos, no watermarks.";
    }
}
