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
                'scheduled_day' => optional($item->scheduled_at)?->locale('it')->isoFormat('dddd D MMMM YYYY'),
            ],
            'avoid_openings' => $this->siblingOpenings($item),
        ];
    }

    /**
     * Prime righe dei post già generati nello stesso piano: ogni item viene
     * generato in isolamento e senza questa lista gli hook si ripetono.
     *
     * @return list<string>
     */
    protected function siblingOpenings(ContentItem $item): array
    {
        if (!$item->content_plan_id) {
            return [];
        }

        return ContentItem::query()
            ->where('content_plan_id', $item->content_plan_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_caption')
            ->orderByDesc('ai_generated_at')
            ->limit(12)
            ->pluck('ai_caption')
            ->map(fn ($caption) => Str::limit(trim(strtok((string) $caption, "\n")), 90, '…'))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
        $services = (string) data_get($ctx, 'brand.services', '');
        $visual = (string) data_get($ctx, 'brand.visual_style', '');
        $tone = (string) data_get($ctx, 'plan.tone', '');
        $topicTitle = trim((string) data_get($ctx, 'topic.title', ''));
        $subject = $topicTitle !== '' ? $topicTitle : trim((string) ($item->ai_caption ?: $item->title ?: ''));

        $vertical = in_array($item->format, ['story', 'reel'], true);

        return "High-quality social media photo for {$brandName}"
            . ($industry !== '' ? " ({$industry})" : '')
            . ". Visual concept: {$subject}. "
            . ($services !== '' ? 'Show their real offering: ' . Str::limit($services, 160, '') . '. ' : '')
            . ($visual !== ''
                ? "Brand visual style: {$visual}. "
                : 'Natural light, authentic setting, candid rather than stock-photo look. ')
            . ($tone !== '' ? "Mood: {$tone}. " : '')
            . ($vertical ? 'Vertical composition with clear focal subject. ' : '')
            . "No text, no logos, no watermarks.";
    }
}
