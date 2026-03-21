<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationAttempt;
use App\Models\GenerationRun;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\GenerationMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_aggregates_cost_latency_and_rates_from_generation_runs_and_attempts(): void
    {
        [$tenantA, $userA] = $this->bootstrapTenant('metrics-a', 'Tenant Metrics A');
        [$tenantB, $userB] = $this->bootstrapTenant('metrics-b', 'Tenant Metrics B');

        $planA = $this->createPlan($tenantA, $userA, 'Plan A');
        $planB = $this->createPlan($tenantB, $userB, 'Plan B');

        $itemA1 = $this->createContentItem($tenantA, $userA, $planA, [
            'format' => 'post',
            'ai_status' => 'done',
        ]);
        $itemA2 = $this->createContentItem($tenantA, $userA, $planA, [
            'format' => 'reel',
            'ai_status' => 'error',
            'ai_error' => 'Video generation timeout after 420s',
        ]);
        $itemB1 = $this->createContentItem($tenantB, $userB, $planB, [
            'format' => 'reel',
            'ai_status' => 'done',
        ]);

        $runA1 = GenerationRun::create([
            'tenant_id' => $tenantA->id,
            'content_item_id' => $itemA1->id,
            'content_plan_id' => $planA->id,
            'run_key' => 'metrics-run-a1',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'attempt_count' => 2,
            'retry_count' => 1,
            'estimated_cost_usd' => 0.33,
            'actual_cost_usd' => null,
            'fallback_used' => false,
            'downgrade_used' => false,
            'segment_count' => 0,
            'final_provider' => 'runway',
            'runtime_ms' => 5000,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'completed_at' => now()->subMinutes(9),
        ]);

        GenerationAttempt::create([
            'generation_run_id' => $runA1->id,
            'tenant_id' => $tenantA->id,
            'content_item_id' => $itemA1->id,
            'sequence' => 1,
            'type' => 'text',
            'stage' => 'text_blueprint',
            'step' => 'text_blueprint',
            'status' => 'succeeded',
            'provider_requested' => 'openai',
            'provider_effective' => 'openai',
            'model_requested' => 'gpt-4.1-mini',
            'model_effective' => 'gpt-4.1-mini',
            'retry_index' => 1,
            'estimated_cost_usd' => 0.03,
            'token_usage' => ['input_tokens' => 10000, 'output_tokens' => 5000, 'total_tokens' => 15000],
            'final_provider' => 'openai',
            'runtime_ms' => 1000,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10)->addSecond(),
            'completed_at' => now()->subMinutes(10)->addSecond(),
        ]);

        GenerationAttempt::create([
            'generation_run_id' => $runA1->id,
            'tenant_id' => $tenantA->id,
            'content_item_id' => $itemA1->id,
            'sequence' => 2,
            'type' => 'image',
            'stage' => 'visual_asset',
            'step' => 'visual_asset',
            'status' => 'succeeded',
            'provider_requested' => 'runway',
            'provider_effective' => 'runway',
            'model_requested' => 'gen4.5',
            'model_effective' => 'gen4.5',
            'retry_index' => 0,
            'estimated_cost_usd' => 0.30,
            'final_provider' => 'runway',
            'runtime_ms' => 4000,
            'started_at' => now()->subMinutes(10)->addSecond(),
            'finished_at' => now()->subMinutes(10)->addSeconds(5),
            'completed_at' => now()->subMinutes(10)->addSeconds(5),
        ]);

        $runA2 = GenerationRun::create([
            'tenant_id' => $tenantA->id,
            'content_item_id' => $itemA2->id,
            'content_plan_id' => $planA->id,
            'run_key' => 'metrics-run-a2',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'failed',
            'format' => 'reel',
            'platform' => 'instagram',
            'attempt_count' => 1,
            'retry_count' => 0,
            'estimated_cost_usd' => 0.10,
            'actual_cost_usd' => 0.08,
            'fallback_used' => true,
            'downgrade_used' => true,
            'segment_count' => 1,
            'final_provider' => 'openai',
            'failure_mode' => 'timeout',
            'runtime_ms' => 2000,
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(8)->addSeconds(2),
            'failed_at' => now()->subMinutes(8)->addSeconds(2),
        ]);

        GenerationAttempt::create([
            'generation_run_id' => $runA2->id,
            'tenant_id' => $tenantA->id,
            'content_item_id' => $itemA2->id,
            'sequence' => 1,
            'type' => 'video',
            'stage' => 'visual_asset',
            'step' => 'visual_asset',
            'status' => 'failed',
            'provider_requested' => 'openai',
            'provider_effective' => 'openai',
            'model_requested' => 'sora-2',
            'model_effective' => 'sora-2',
            'retry_index' => 0,
            'estimated_cost_usd' => 0.10,
            'actual_cost_usd' => 0.08,
            'fallback_used' => true,
            'downgrade_used' => true,
            'segment_count' => 1,
            'final_provider' => 'openai',
            'failure_mode' => 'timeout',
            'runtime_ms' => 2000,
            'error_message' => 'Video generation timeout after 420s',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(8)->addSeconds(2),
            'failed_at' => now()->subMinutes(8)->addSeconds(2),
        ]);

        $runB1 = GenerationRun::create([
            'tenant_id' => $tenantB->id,
            'content_item_id' => $itemB1->id,
            'content_plan_id' => $planB->id,
            'run_key' => 'metrics-run-b1',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'reel',
            'platform' => 'instagram',
            'attempt_count' => 1,
            'retry_count' => 0,
            'estimated_cost_usd' => 1.20,
            'actual_cost_usd' => null,
            'fallback_used' => false,
            'downgrade_used' => false,
            'segment_count' => 2,
            'final_provider' => 'kling',
            'runtime_ms' => 5000,
            'started_at' => now()->subMinutes(7),
            'finished_at' => now()->subMinutes(7)->addSeconds(5),
            'completed_at' => now()->subMinutes(7)->addSeconds(5),
        ]);

        GenerationAttempt::create([
            'generation_run_id' => $runB1->id,
            'tenant_id' => $tenantB->id,
            'content_item_id' => $itemB1->id,
            'sequence' => 1,
            'type' => 'video',
            'stage' => 'visual_asset',
            'step' => 'visual_asset',
            'status' => 'succeeded',
            'provider_requested' => 'kling',
            'provider_effective' => 'kling',
            'model_requested' => 'kling-v1-6',
            'model_effective' => 'kling-v1-6',
            'retry_index' => 0,
            'estimated_cost_usd' => 1.20,
            'segment_count' => 2,
            'final_provider' => 'kling',
            'runtime_ms' => 5000,
            'started_at' => now()->subMinutes(7),
            'finished_at' => now()->subMinutes(7)->addSeconds(5),
            'completed_at' => now()->subMinutes(7)->addSeconds(5),
        ]);

        $service = app(GenerationMetricsService::class);

        $costByTenant = $service->costByTenant();
        $this->assertSame([1, 2], $costByTenant->pluck('runs_count')->all());
        $this->assertSame('Tenant Metrics B', $costByTenant->first()['tenant_name']);
        $this->assertEqualsWithDelta(1.20, $costByTenant->first()['effective_cost_usd'], 0.0001);
        $this->assertSame('Tenant Metrics A', $costByTenant->last()['tenant_name']);
        $this->assertEqualsWithDelta(0.41, $costByTenant->last()['effective_cost_usd'], 0.0001);

        $costByProvider = $service->costByProvider()->keyBy('provider');
        $this->assertEqualsWithDelta(0.30, data_get($costByProvider, 'runway.effective_cost_usd'), 0.0001);
        $this->assertEqualsWithDelta(0.11, data_get($costByProvider, 'openai.effective_cost_usd'), 0.0001);
        $this->assertEqualsWithDelta(1.20, data_get($costByProvider, 'kling.effective_cost_usd'), 0.0001);

        $latencyByProvider = $service->averageLatencyByProvider()->keyBy('provider');
        $this->assertSame(4000, data_get($latencyByProvider, 'runway.avg_runtime_ms'));
        $this->assertSame(1500, data_get($latencyByProvider, 'openai.avg_runtime_ms'));
        $this->assertSame(5000, data_get($latencyByProvider, 'kling.avg_runtime_ms'));

        $this->assertSame(1, $service->failureRate()['failed_runs']);
        $this->assertSame(3, $service->failureRate()['total_runs']);
        $this->assertEquals(0.3333, $service->failureRate()['rate']);
        $this->assertEquals(0.3333, $service->retryRate()['rate']);
        $this->assertEquals(0.3333, $service->downgradeRate()['rate']);
        $this->assertEquals(0.3333, $service->fallbackRate()['rate']);

        $failureModes = $service->failureModes()->keyBy('failure_mode');
        $this->assertSame(1, data_get($failureModes, 'timeout.runs_count'));
    }

    private function bootstrapTenant(string $slug, string $name): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => $name,
            'industry' => 'Services',
            'services' => 'AI content',
            'target' => 'SMB',
            'cta' => 'Contattaci',
            'default_tone' => 'professionale',
        ]);

        return [$tenant, $user];
    }

    private function createPlan(Tenant $tenant, User $user, string $name): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => $name,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }

    private function createContentItem(Tenant $tenant, User $user, ContentPlan $plan, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addDay(),
            'status' => 'draft',
            'title' => 'Metrics item',
            'caption' => null,
            'hashtags' => [],
            'assets' => [],
            'ai_status' => 'queued',
            'ai_meta' => [],
        ], $overrides));
    }
}
