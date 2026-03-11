<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
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
}
