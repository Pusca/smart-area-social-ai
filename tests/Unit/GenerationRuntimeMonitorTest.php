<?php

namespace Tests\Unit;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\GenerationRuntimeMonitor;
use App\Services\GenerationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationRuntimeMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_nudges_recent_queued_items_before_they_become_stale(): void
    {
        config()->set('queue.default', 'database');
        config()->set('generation.queued_nudge_after_seconds', 15);
        config()->set('generation.queued_recovery_retry_seconds', 45);
        config()->set('generation.queued_stale_after_seconds', 300);
        config()->set('generation.queue_auto_kick', false);

        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Piano demo',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'draft',
            'settings' => ['mode' => 'single_manual'],
        ]);

        $item = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'reel',
            'status' => 'draft',
            'title' => 'Demo reel',
            'caption' => 'Brief demo',
            'ai_status' => 'queued',
            'ai_meta' => [
                'source' => 'manual_single_content',
                'generation_monitor' => [
                    'queue_reference_at' => now()->subSeconds(30)->toDateTimeString(),
                ],
            ],
        ]);

        $monitor = new class(app(GenerationAuditService::class)) extends GenerationRuntimeMonitor {
            public bool $drained = false;

            protected function shouldUseHttpDrainFallback(): bool
            {
                return true;
            }

            protected function drainQueueOnceViaHttp(ContentItem $item): bool
            {
                $this->drained = true;

                return true;
            }
        };

        $this->assertTrue($monitor->reconcileContentItem($item));

        $item->refresh();
        $this->assertTrue($monitor->drained);
        $this->assertNotEmpty((string) data_get($item->ai_meta, 'generation_monitor.last_recovery_attempt_at'));
        $this->assertSame('queued', $item->ai_status);
    }
}
