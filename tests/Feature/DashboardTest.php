<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\Tenant;
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
}
