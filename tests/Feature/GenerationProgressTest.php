<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_content_generation_page_and_status_are_available_for_owner(): void
    {
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
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Demo post',
            'caption' => 'Brief demo',
            'ai_status' => 'queued',
            'ai_meta' => ['source' => 'manual_single_content'],
        ]);

        $this->actingAs($user)
            ->get(route('posts.generating', $item))
            ->assertOk()
            ->assertSee('Sto preparando il contenuto');

        $this->actingAs($user)
            ->getJson(route('posts.generation.status', $item))
            ->assertOk()
            ->assertJsonPath('item_id', $item->id)
            ->assertJsonPath('ai_status', 'queued')
            ->assertJsonPath('active', true)
            ->assertJsonPath('redirect_url', route('posts.edit', $item));
    }

    public function test_plan_generation_page_is_available_for_quickstart_context(): void
    {
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
            'name' => 'Demo rapida',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'draft',
            'settings' => ['mode' => 'onboarding_quickstart_demo'],
        ]);

        $this->actingAs($user)
            ->get(route('plans.generating', ['contentPlan' => $plan, 'context' => 'quickstart']))
            ->assertOk()
            ->assertSee('Sto completando la demo iniziale');
    }

    public function test_single_content_generation_status_marks_stale_queued_items_as_error(): void
    {
        config()->set('generation.queued_stale_after_seconds', 300);
        config()->set('generation.queued_recovery_grace_seconds', 60);
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
            'ai_meta' => ['source' => 'manual_single_content'],
            'updated_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->getJson(route('posts.generation.status', $item))
            ->assertOk()
            ->assertJsonPath('ai_status', 'error')
            ->assertJsonPath('active', false)
            ->assertJsonPath('error', true);

        $item->refresh();
        $this->assertSame('error', $item->ai_status);
        $this->assertStringContainsString('QUEUE_STALE_TIMEOUT', (string) $item->ai_error);
    }

    public function test_single_content_generation_status_marks_stale_pending_items_as_error(): void
    {
        config()->set('generation.pending_stale_after_seconds', 1200);

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
            'ai_status' => 'pending',
            'ai_meta' => ['source' => 'manual_single_content'],
            'updated_at' => now()->subMinutes(40),
            'created_at' => now()->subMinutes(40),
        ]);

        GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'run-stale-pending',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'running',
            'format' => 'reel',
            'platform' => 'instagram',
            'started_at' => now()->subMinutes(40),
        ]);

        $this->actingAs($user)
            ->getJson(route('posts.generation.status', $item))
            ->assertOk()
            ->assertJsonPath('ai_status', 'error')
            ->assertJsonPath('active', false)
            ->assertJsonPath('error', true);

        $item->refresh();
        $this->assertSame('error', $item->ai_status);
        $this->assertStringContainsString('JOB_STALE_TIMEOUT', (string) $item->ai_error);
        $this->assertSame(
            'failed',
            (string) GenerationRun::query()->where('run_key', 'run-stale-pending')->value('status')
        );
    }

    public function test_plan_progress_reconciles_stale_items_before_reporting_counts(): void
    {
        config()->set('generation.queued_stale_after_seconds', 300);
        config()->set('generation.queued_recovery_grace_seconds', 60);
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

        ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'reel',
            'status' => 'draft',
            'title' => 'Demo reel',
            'caption' => 'Brief demo',
            'ai_status' => 'queued',
            'ai_meta' => ['source' => 'manual_single_content'],
            'updated_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->getJson(route('wizard.progress.plan', $plan))
            ->assertOk()
            ->assertJsonPath('counts.queued', 0)
            ->assertJsonPath('counts.error', 1)
            ->assertJsonPath('active', false);
    }
}
