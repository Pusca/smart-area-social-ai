<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_generate_ai_for_another_tenants_content_item(): void
    {
        Queue::fake();

        [$tenantA, $userA] = $this->createTenantUserPair('tenant-a');
        [$tenantB] = $this->createTenantUserPair('tenant-b');

        $plan = ContentPlan::create([
            'tenant_id' => $tenantB->id,
            'created_by' => null,
            'name' => 'Tenant B Plan',
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-16',
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $item = ContentItem::create([
            'tenant_id' => $tenantB->id,
            'content_plan_id' => $plan->id,
            'created_by' => null,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Tenant B Item',
            'ai_status' => 'draft',
        ]);

        $this->actingAs($userA)
            ->post(route('ai.content.generate', $item))
            ->assertForbidden();

        Queue::assertNotPushed(GenerateAiForContentItem::class);
    }

    public function test_user_cannot_generate_ai_for_another_tenants_plan(): void
    {
        Queue::fake();

        [$tenantA, $userA] = $this->createTenantUserPair('tenant-c');
        [$tenantB] = $this->createTenantUserPair('tenant-d');

        $plan = ContentPlan::create([
            'tenant_id' => $tenantB->id,
            'created_by' => null,
            'name' => 'Tenant D Plan',
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-16',
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $this->actingAs($userA)
            ->post(route('ai.plan.generate', $plan))
            ->assertForbidden();

        Queue::assertNotPushed(GenerateAiForContentItem::class);
    }

    public function test_user_cannot_queue_bulk_ai_generation_for_another_tenants_items(): void
    {
        Queue::fake();

        [$tenantA, $userA] = $this->createTenantUserPair('tenant-e');
        [$tenantB] = $this->createTenantUserPair('tenant-f');

        $plan = ContentPlan::create([
            'tenant_id' => $tenantB->id,
            'created_by' => null,
            'name' => 'Tenant F Plan',
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-16',
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $item = ContentItem::create([
            'tenant_id' => $tenantB->id,
            'content_plan_id' => $plan->id,
            'created_by' => null,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Tenant F Item',
            'ai_status' => 'draft',
        ]);

        $this->actingAs($userA)
            ->post(route('ai.generate'), [
                'content_item_ids' => [$item->id],
            ])
            ->assertForbidden();

        Queue::assertNotPushed(GenerateAiForContentItem::class);
    }

    private function createTenantUserPair(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => strtoupper($slug),
            'slug' => $slug,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        return [$tenant, $user];
    }
}
