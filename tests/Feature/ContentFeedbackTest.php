<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_dislike_feedback_can_trigger_regeneration_and_store_active_request(): void
    {
        Queue::fake();

        [$tenant, $user, $item] = $this->makeTenantUserAndItem();

        $response = $this->actingAs($user)->post(route('posts.feedback.store', $item), [
            'sentiment' => 'dislike',
            'category' => 'realism',
            'reason' => 'Il locale va bene, ma le persone sembrano finte e poco credibili.',
            'action' => 'regenerate',
        ]);

        $response->assertRedirect(route('posts.edit', $item));

        $entry = ContentFeedbackEntry::query()->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('dislike', $entry->sentiment);
        $this->assertSame('realism', $entry->category);
        $this->assertSame('person_not_consistent', $entry->normalized_category);
        $this->assertSame('high', $entry->severity);
        $this->assertSame('visual_first', $entry->scope);
        $this->assertSame('regenerate', $entry->action);

        $item->refresh();

        $this->assertSame('queued', $item->ai_status);
        $this->assertSame((int) $entry->id, (int) data_get($item->ai_meta, 'feedback_loop.active_request.feedback_id'));
        $this->assertSame('realism', data_get($item->ai_meta, 'feedback_loop.active_request.category'));
        $this->assertSame('person_not_consistent', data_get($item->ai_meta, 'feedback_loop.active_request.normalized_category'));
        $this->assertSame('high', data_get($item->ai_meta, 'feedback_loop.active_request.severity'));
        $this->assertStringContainsString('persone sembrano finte', (string) data_get($item->ai_meta, 'feedback_loop.active_request.reason'));
        $this->assertSame(1, (int) data_get($item->ai_meta, 'memory_summary.feedback_summary.total_count'));
        $this->assertSame(1, (int) data_get($item->ai_meta, 'memory_summary.feedback_summary.dislikes_count'));
        $this->assertContains('person_not_consistent', data_get($item->ai_meta, 'memory_summary.feedback_summary.priority_categories', []));

        Queue::assertPushed(GenerateAiForContentItem::class, function (GenerateAiForContentItem $job) use ($item) {
            return $job->contentItemId === (int) $item->id;
        });
    }

    public function test_memory_builder_includes_structured_feedback_preferences_and_objections(): void
    {
        [$tenant, $user, $itemA] = $this->makeTenantUserAndItem();

        $itemB = ContentItem::query()->create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $itemA->content_plan_id,
            'created_by' => $user->id,
            'platform' => 'instagram,facebook',
            'format' => 'reel',
            'status' => 'draft',
            'title' => 'Reel prodotto',
            'caption' => 'Focus prodotto',
            'hashtags' => [],
            'assets' => [],
            'ai_meta' => [],
            'rubric' => 'Product',
            'pillar' => 'Vendita',
            'content_angle' => 'Prodotto in evidenza',
            'ai_status' => 'done',
            'ai_caption' => 'Reel ben riuscito',
        ]);

        ContentFeedbackEntry::query()->create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $itemA->id,
            'user_id' => $user->id,
            'sentiment' => 'like',
            'category' => null,
            'normalized_category' => null,
            'severity' => 'low',
            'scope' => 'full',
            'reason' => 'Molto realistico e in linea con il brand.',
            'action' => 'record_only',
            'meta' => [],
        ]);

        ContentFeedbackEntry::query()->create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $itemB->id,
            'user_id' => $user->id,
            'sentiment' => 'dislike',
            'category' => 'tone_of_voice',
            'normalized_category' => null,
            'severity' => null,
            'scope' => 'copy_first',
            'reason' => 'Il tono e troppo freddo, deve sembrare piu vicino e piu umano.',
            'action' => 'record_only',
            'scores' => [
                'brand_fit_score' => 2,
                'quality_score' => 3,
            ],
            'meta' => [],
        ]);

        $memory = app(MemoryBuilderService::class)->buildForTenant((int) $tenant->id, 40);

        $this->assertSame(2, (int) data_get($memory, 'feedback_summary.total_count'));
        $this->assertSame(1, (int) data_get($memory, 'feedback_summary.likes_count'));
        $this->assertSame(1, (int) data_get($memory, 'feedback_summary.dislikes_count'));
        $this->assertContains('post', data_get($memory, 'feedback_summary.preferred_formats', []));
        $this->assertContains('off_brand', data_get($memory, 'feedback_summary.priority_categories', []));
        $this->assertSame(1, (int) data_get($memory, 'feedback_summary.category_breakdown.off_brand'));
        $this->assertSame(1, (int) data_get($memory, 'feedback_summary.severity_breakdown.high'));
        $this->assertSame(2.0, (float) data_get($memory, 'feedback_summary.score_averages.brand_fit_score'));
        $this->assertNotEmpty(data_get($memory, 'feedback_summary.positive_signals', []));
        $this->assertNotEmpty(data_get($memory, 'feedback_summary.hard_avoid_rules', []));
        $this->assertContains('off_brand', data_get($memory, 'feedback_summary.retrieval_hints.high_severity_categories', []));
        $this->assertSame('off_brand', data_get($memory, 'feedback_summary.recent_objections.0.normalized_category'));
    }

    /**
     * @return array{0:Tenant,1:User,2:ContentItem}
     */
    private function makeTenantUserAndItem(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Feedback',
            'slug' => 'tenant-feedback',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $plan = ContentPlan::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Piano Feedback',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'draft',
            'settings' => [
                'goal' => 'Awareness',
                'tone' => 'amichevole',
                'posts_total' => 3,
                'platforms' => ['instagram', 'facebook'],
                'formats' => ['post', 'reel'],
            ],
            'strategy' => [],
        ]);

        $item = ContentItem::query()->create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram,facebook',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Post showroom',
            'caption' => 'Showroom premium',
            'hashtags' => [],
            'assets' => [],
            'ai_meta' => [
                'source' => 'manual_single_content',
            ],
            'rubric' => 'Brand',
            'pillar' => 'Showroom',
            'content_angle' => 'Presentazione ambiente',
            'ai_status' => 'done',
            'ai_caption' => 'Caption iniziale',
            'ai_image_prompt' => 'Prompt iniziale',
        ]);

        return [$tenant, $user, $item];
    }
}
