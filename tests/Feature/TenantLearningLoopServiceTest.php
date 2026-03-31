<?php

namespace Tests\Feature;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationRun;
use App\Models\SocialPublication;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\Learning\TenantLearningLoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLearningLoopServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_learned_preferences_for_the_tenant(): void
    {
        config()->set('social_manager.learning.min_events_for_bias', 1);

        [$tenant, $user, $profile] = $this->bootstrapTenant('tenant-learning-loop');
        $plan = $this->createPlan($tenant, $user);

        $likedItem = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'reel',
            'scheduled_at' => now()->subDays(2),
            'status' => 'published',
            'title' => 'Liked reel',
            'ai_status' => 'done',
            'ai_caption' => 'Caption forte.',
            'ai_meta' => [
                'viral_angle' => ['mechanic' => 'authority_problem_reframe'],
                'hook_meta' => ['cta_mode' => 'consultative_soft'],
            ],
        ]);

        $dislikedItem = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'story',
            'scheduled_at' => now()->subDays(1),
            'status' => 'failed',
            'title' => 'Disliked story',
            'ai_status' => 'done',
            'ai_caption' => 'Caption debole.',
            'ai_meta' => [
                'item_brain' => [
                    'trend_opportunity' => ['topic' => 'saveable checklist carousel'],
                    'content_strategy_type' => 'trend-aware',
                ],
            ],
        ]);

        ContentFeedbackEntry::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $likedItem->id,
            'user_id' => $user->id,
            'sentiment' => ContentFeedbackEntry::SENTIMENT_LIKE,
            'action' => ContentFeedbackEntry::ACTION_RECORD_ONLY,
            'scope' => ContentFeedbackEntry::SCOPE_FULL,
            'normalized_category' => 'other',
            'severity' => ContentFeedbackEntry::SEVERITY_LOW,
            'reason' => 'Questo hook funziona.',
            'scores' => ['overall' => 0.9],
        ]);

        ContentFeedbackEntry::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $dislikedItem->id,
            'user_id' => $user->id,
            'sentiment' => ContentFeedbackEntry::SENTIMENT_DISLIKE,
            'action' => ContentFeedbackEntry::ACTION_REGENERATE,
            'scope' => ContentFeedbackEntry::SCOPE_VISUAL_FIRST,
            'normalized_category' => 'not_publishable',
            'severity' => ContentFeedbackEntry::SEVERITY_HIGH,
            'reason' => 'Formato troppo debole.',
            'scores' => ['overall' => 0.2],
        ]);

        GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $dislikedItem->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'learning-run-1',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'failed',
            'format' => 'story',
            'platform' => 'instagram',
            'final_provider' => 'runway',
            'failure_mode' => 'identity_miss',
            'publish_gate' => ['decision' => 'blocked'],
            'effective_output' => ['video_provider' => 'runway'],
            'started_at' => now()->subDay(),
            'failed_at' => now()->subDay()->addMinute(),
        ]);

        SocialPublication::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $likedItem->id,
            'provider' => 'meta',
            'platform' => 'instagram',
            'status' => 'published',
            'media_type' => 'video',
            'caption' => 'Caption forte.',
            'media_url' => 'https://example.test/video.mp4',
            'scheduled_for' => now()->subDays(2),
            'published_at' => now()->subDays(2),
            'response_meta' => ['impressions' => 1500],
        ]);

        $learning = app(TenantLearningLoopService::class)->refreshForTenant((int) $tenant->id);
        $profile->refresh();

        $this->assertNotEmpty((array) ($learning['preferred_hook_families'] ?? []));
        $this->assertContains('consultative_soft', (array) ($learning['preferred_cta_styles'] ?? []));
        $this->assertNotEmpty((array) ($learning['formats_that_underperform'] ?? []));
        $this->assertSame($learning, (array) $profile->learning_preferences);
    }

    public function test_it_does_not_try_to_create_an_incomplete_tenant_profile_when_missing(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Learning Missing Profile',
            'slug' => 'tenant-learning-missing-profile',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $plan = $this->createPlan($tenant, $user);

        ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->subDay(),
            'status' => 'draft',
            'title' => 'Learning item',
            'ai_status' => 'done',
            'ai_caption' => 'Caption',
            'ai_meta' => [],
        ]);

        $learning = app(TenantLearningLoopService::class)->refreshForTenant((int) $tenant->id);

        $this->assertIsArray($learning);
        $this->assertDatabaseMissing('tenant_profiles', [
            'tenant_id' => $tenant->id,
        ]);
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Learning',
            'slug' => $slug,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $profile = TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Learning Brand',
            'industry' => 'SaaS',
            'services' => 'AI social manager',
            'target' => 'PMI',
            'cta' => 'Scrivici ora.',
        ]);

        return [$tenant, $user, $profile];
    }

    private function createPlan(Tenant $tenant, User $user): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Learning Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }
}
