<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_content_creation_is_blocked_when_tenant_reaches_content_limit(): void
    {
        Queue::fake();
        config()->set('generation.strict_asset_mode', false);

        $tenant = Tenant::create([
            'name' => 'Tenant Limitato',
            'slug' => 'tenant-limitato',
            'plan' => 'trial',
            'is_active' => true,
            'limits' => ['max_content_items' => 1],
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Piano base',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'draft',
            'settings' => ['mode' => 'single_manual'],
            'strategy' => [],
        ]);

        ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Contenuto esistente',
            'caption' => 'Gia presente',
            'ai_status' => 'done',
        ]);

        $this->actingAs($user)
            ->from(route('posts.create'))
            ->post(route('posts.store'), [
                'format' => 'post',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'generation_brief' => 'Nuovo contenuto da bloccare',
            ])
            ->assertRedirect(route('posts.create'))
            ->assertSessionHasErrors(['generation_brief']);

        $this->assertSame(1, ContentItem::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_inactive_tenant_is_blocked_for_regular_user_but_accessible_via_admin_impersonation(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Fermo',
            'slug' => 'tenant-fermo',
            'plan' => 'trial',
            'is_active' => false,
            'limits' => [],
        ]);

        $workspaceUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $admin = User::factory()->create([
            'email' => 'puscastanislav0@gmail.com',
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);

        $this->actingAs($workspaceUser)
            ->get(route('dashboard'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('admin.tenants.impersonate', $tenant))
            ->assertOk()
            ->assertSee('Modalita admin attiva nel workspace');
    }
}
