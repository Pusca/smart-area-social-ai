<?php

namespace App\Jobs;

use App\Models\ContentPlan;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step di ideazione a livello piano: una sola chiamata AI genera un argomento
 * distinto per ogni post, poi accoda la generazione dei singoli contenuti.
 * Senza questo step tutti i post condividerebbero lo stesso contesto
 * e uscirebbero quasi identici.
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
            $settings = is_array($plan->settings) ? $plan->settings : [];

            $context = [
                'brand' => $settings['tenant_profile'] ?? [],
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
                'schedule' => $items->map(fn ($i) => [
                    'platform' => $i->platform,
                    'format' => $i->format,
                    'date' => optional($i->scheduled_at)->toDateString(),
                ])->all(),
            ];

            $result = $openAi->generatePlanTopics($context, $items->count());
            $topics = $result['topics'];

            foreach ($items as $index => $item) {
                $topic = $topics[$index % count($topics)];

                $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
                $meta['topic'] = $topic;
                if (!empty($result['usage'])) {
                    $meta['usage']['topics'] = $result['usage'];
                }
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
}
