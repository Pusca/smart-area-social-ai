<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\TenantProfile;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step di ideazione a livello piano: genera un argomento distinto per ogni
 * post evitando quelli già usati nei piani precedenti, poi accoda la
 * generazione dei singoli contenuti.
 */
class GeneratePlanTopics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $contentPlanId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $plan = ContentPlan::with('items')->find($this->contentPlanId);
        if (!$plan) {
            return;
        }

        $items = $plan->items->sortBy('scheduled_at')->values();
        if ($items->isEmpty()) {
            return;
        }

        try {
            $topics = $this->ideateTopics($openAi, $plan, $items->count());

            foreach ($items as $index => $item) {
                // Riuso col modulo solo come ultima spiaggia se l'AI ha reso meno topic del previsto
                $topic = $topics[$index] ?? $topics[$index % count($topics)];

                $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
                $meta['topic'] = $topic;
                $item->ai_meta = $meta;

                if (!empty($topic['title'])) {
                    $item->title = Str::limit($topic['title'], 110, '');
                }

                $item->save();
            }
        } catch (Throwable $e) {
            // Fallback: i post si generano comunque, solo senza argomenti dedicati
            Log::warning('GeneratePlanTopics failed, generating items without topics', [
                'content_plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($items as $item) {
            GenerateAiForContentItem::dispatch($item->id);
        }
    }

    /**
     * Chiede i topic all'AI, ripetendo la chiamata (max 3) finché non ne ha
     * abbastanza, e passando ogni volta la lista di argomenti da evitare.
     *
     * @return list<array{title: string, angle: string, key_points: array}>
     */
    protected function ideateTopics(OpenAiService $openAi, ContentPlan $plan, int $needed): array
    {
        $settings = is_array($plan->settings) ? $plan->settings : [];

        $profile = TenantProfile::where('tenant_id', $plan->tenant_id)->first();
        $brand = $profile ? $profile->toAiContext() : ($settings['tenant_profile'] ?? []);

        $baseContext = [
            'brand' => $brand,
            'plan' => [
                'goal' => $settings['goal'] ?? null,
                'tone' => $settings['tone'] ?? null,
                'platforms' => $settings['platforms'] ?? [],
                'formats' => $settings['formats'] ?? [],
                'date_range' => [
                    optional($plan->start_date)->toDateString(),
                    optional($plan->end_date)->toDateString(),
                ],
            ],
            'schedule' => $plan->items->sortBy('scheduled_at')->values()->map(fn ($i) => [
                'platform' => $i->platform,
                'format' => $i->format,
                'date' => optional($i->scheduled_at)->toDateString(),
            ])->all(),
        ];

        $avoid = $this->previousTopicTitles($plan);

        $topics = [];
        for ($attempt = 0; $attempt < 3 && count($topics) < $needed; $attempt++) {
            $context = $baseContext;
            $context['avoid_topics'] = array_values(array_merge($avoid, array_column($topics, 'title')));

            $result = $openAi->generatePlanTopics($context, $needed - count($topics));
            $topics = array_merge($topics, $result['topics']);
        }

        if ($topics === []) {
            throw new \RuntimeException('Nessun topic generato per il piano');
        }

        return array_slice(array_values($topics), 0, $needed);
    }

    /**
     * Argomenti già usati nei piani precedenti del tenant: senza questa
     * memoria, ogni nuovo piano ripropone le stesse idee.
     *
     * @return list<string>
     */
    protected function previousTopicTitles(ContentPlan $plan): array
    {
        return ContentItem::query()
            ->where('tenant_id', $plan->tenant_id)
            ->where('content_plan_id', '!=', $plan->id)
            ->whereNotNull('ai_meta')
            ->latest('id')
            ->limit(150)
            ->get()
            ->map(fn ($i) => trim((string) Arr::get($i->ai_meta, 'topic.title', '')))
            ->filter()
            ->unique()
            ->take(60)
            ->values()
            ->all();
    }
}
