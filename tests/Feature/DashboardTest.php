<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_zero_time_progress_before_plan_start(): void
    {
        $tenant = Tenant::create([
            'name' => 'Dashboard Tenant',
            'slug' => 'dashboard-tenant',
        ]);
        $this->markTenantOnboardingComplete($tenant);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Future Plan',
            'start_date' => '2026-03-17',
            'end_date' => '2026-03-23',
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewHas('planDaysElapsed', 0)
            ->assertViewHas('planTimeProgress', 0);
    }

    public function test_dashboard_shows_generation_link_when_items_are_processing(): void
    {
        $tenant = Tenant::create([
            'name' => 'Dashboard Tenant',
            'slug' => 'dashboard-tenant',
        ]);
        $this->markTenantOnboardingComplete($tenant);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Current Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Queued content',
            'caption' => 'Brief',
            'ai_status' => 'queued',
            'ai_meta' => ['source' => 'manual_single_content'],
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Apri generazioni')
            ->assertSee(route('posts.generation.index'), false);
    }

    private function markTenantOnboardingComplete(Tenant $tenant): void
    {
        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => $tenant->name,
            'industry' => 'Demo',
            'services' => 'Demo service',
            'target' => 'Demo target',
            'default_tone' => 'professionale',
            'default_goal' => 'Awareness',
            'onboarding_completed_at' => now(),
        ]);
    }
}
