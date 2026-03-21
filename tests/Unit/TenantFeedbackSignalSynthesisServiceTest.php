<?php

namespace Tests\Unit;

use App\Models\ContentFeedbackEntry;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Feedback\TenantFeedbackSignalSynthesisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantFeedbackSignalSynthesisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_synthesizes_structured_feedback_signals_for_a_tenant(): void
    {
        [$tenant, $user, $itemA, $itemB] = $this->makeFixtures();

        ContentFeedbackEntry::query()->create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $itemA->id,
            'user_id' => $user->id,
            'sentiment' => 'like',
            'scope' => 'full',
            'severity' => 'low',
            'reason' => 'Mi piace il tono caldo e il formato post.',
            'action' => 'record_only',
            'meta' => [],
        ]);

        ContentFeedbackEntry::query()->create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $itemB->id,
            'user_id' => $user->id,
            'sentiment' => 'dislike',
            'category' => 'visual_composition',
            'normalized_category' => 'product_deformed',
            'severity' => 'high',
            'scope' => 'visual_first',
            'reason' => 'Il prodotto sembra deformato e il packaging non torna.',
            'action' => 'regenerate',
            'scores' => [
                'quality_score' => 2,
                'publishability_score' => 1,
            ],
            'meta' => [],
        ]);

        ContentFeedbackEntry::query()->create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $itemB->id,
            'user_id' => $user->id,
            'sentiment' => 'dislike',
            'category' => 'platform_fit',
            'normalized_category' => 'not_publishable',
            'severity' => 'blocking',
            'scope' => 'full',
            'reason' => 'Cosi non e pubblicabile per il brand.',
            'action' => 'record_only',
            'scores' => [
                'publishability_score' => 1,
            ],
            'meta' => [],
        ]);

        $summary = app(TenantFeedbackSignalSynthesisService::class)->buildForTenant((int) $tenant->id, 40);

        $this->assertSame(3, (int) $summary['total_count']);
        $this->assertSame(1, (int) $summary['likes_count']);
        $this->assertSame(2, (int) $summary['dislikes_count']);
        $this->assertContains('product_deformed', $summary['priority_categories']);
        $this->assertContains('not_publishable', $summary['priority_categories']);
        $this->assertSame(1, (int) data_get($summary, 'severity_breakdown.blocking'));
        $this->assertSame(1, (int) data_get($summary, 'severity_breakdown.high'));
        $this->assertSame(1.0, (float) data_get($summary, 'score_averages.publishability_score'));
        $this->assertNotEmpty($summary['reusable_signals']);
        $this->assertContains('not_publishable', data_get($summary, 'retrieval_hints.blocking_categories', []));
        $this->assertContains('product_deformed', data_get($summary, 'retrieval_hints.high_severity_categories', []));
        $this->assertSame('not_publishable', data_get($summary, 'recent_objections.0.normalized_category'));
        $this->assertSame('blocking', data_get($summary, 'recent_objections.0.severity'));
    }

    /**
     * @return array{0:Tenant,1:User,2:ContentItem,3:ContentItem}
     */
    private function makeFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Signals',
            'slug' => 'tenant-signals',
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
            'name' => 'Piano Signals',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [
                'goal' => 'Awareness',
                'tone' => 'umano',
                'posts_total' => 4,
                'platforms' => ['instagram'],
                'formats' => ['post', 'reel'],
            ],
            'strategy' => [],
        ]);

        $itemA = ContentItem::query()->create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Post caldo',
            'caption' => 'Caption calda',
            'hashtags' => [],
            'assets' => [],
            'ai_meta' => [],
            'rubric' => 'Brand',
            'pillar' => 'Awareness',
            'content_angle' => 'Tone test',
            'ai_status' => 'done',
            'ai_caption' => 'Caption calda',
        ]);

        $itemB = ContentItem::query()->create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'reel',
            'status' => 'draft',
            'title' => 'Reel prodotto',
            'caption' => 'Packaging focus',
            'hashtags' => [],
            'assets' => [],
            'ai_meta' => [],
            'rubric' => 'Product',
            'pillar' => 'Conversion',
            'content_angle' => 'Product demo',
            'ai_status' => 'done',
            'ai_caption' => 'Packaging focus',
        ]);

        return [$tenant, $user, $itemA, $itemB];
    }
}
